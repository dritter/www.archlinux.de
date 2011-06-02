#!/usr/bin/php -d memory_limit=256M
<?php
/*
	Copyright 2002-2011 Pierre Schmitz <pierre@archlinux.de>

	This file is part of archlinux.de.

	archlinux.de is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	archlinux.de is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with archlinux.de.  If not, see <http://www.gnu.org/licenses/>.
*/

require (__DIR__.'/../lib/Exceptions.php');
require (__DIR__.'/../lib/AutoLoad.php');

class UpdatePackages extends CronJob {

	private $updatedPackages = false;

	private $selectRepoMTime = null;
	private $selectPackageMTime = null;
	private $updateRepoMTime = null;

	private $selectArchId = null;
	private $insertArchName = null;
	private $arches = array();

	private $selectRepoId = null;
	private $insertRepoName = null;

	private $selectPackageId = null;
	private $updatePackage = null;
	private $insertPackage = null;

	private $selectPackager = null;
	private $insertPackager = null;
	private $packagers = array();

	private $selectGroup = null;
	private $insertGroup = null;
	private $cleanupPackageGroup = null;
	private $insertPackageGroup = null;
	private $groups = array();

	private $selectLicense = null;
	private $insertLicense = null;
	private $cleanupPackageLicense = null;
	private $insertPackageLicense = null;
	private $licenses = array();

	private $cleanupRelation = null;
	private $insertRelation = null;

	private $selectFileIndex = null;
	private $insertFileIndex = null;
	private $cleanupFileIndex = null;
	private $cleanupFiles = null;
	private $insertFiles = null;
	private $insertPackageFileIndex = null;
	private $cleanupPackageFileIndex = null;
	private $files = array();

	public function execute() {
		try {
			DB::beginTransaction();
			$this->prepareQueries();

			foreach (Config::get('packages', 'repositories') as $repo => $arches) {
				foreach ($arches as $arch) {
					$this->printDebug('Processing ['.$repo.'] ('.$arch.')');
					$archId = $this->getArchId($arch);
					$repoId = $this->getRepoId($repo, $archId);

					$this->selectRepoMTime->bindParam('repoId', $repoId, PDO::PARAM_INT);
					$this->selectRepoMTime->execute();
					$repoMTime = $this->selectRepoMTime->fetchColumn();

					$this->selectPackageMTime->bindParam('repoId', $repoId, PDO::PARAM_INT);
					$this->selectPackageMTime->execute();
					$packageMTime = $this->selectPackageMTime->fetchColumn();

					$this->printDebug("\tDownloading...");
					$packages = new PackageDatabase($repo, $arch, $repoMTime, $packageMTime);

					if ($packages->getMTime() > $repoMTime) {
						$packageCount = 0;
						foreach ($packages as $package) {
							$this->printProgress(++$packageCount, $packages->getNewPackageCount(), "\tReading packages: ");
							$this->updatePackage($repoId, $package);
						}

						$this->printDebug("\tCleaning up obsolete packages...");
						$this->cleanupObsoletePackages($repoId, $packageMTime, $packages->getOldPackageNames());

						$this->updateRepoMTime->bindValue('mtime', $packages->getMTime(), PDO::PARAM_INT);
						$this->updateRepoMTime->bindParam('repoId', $repoId, PDO::PARAM_INT);
						$this->updateRepoMTime->execute();
					}
				}
				$this->groups = array();
				$this->files = array();
			}

			$this->printDebug("Cleaning up obsolete repositories...");
			$this->cleanupObsoleteRepositories();

			if ($this->updatedPackages) {
				$this->printDebug("Cleaning up obsolete database entries...");
				$this->cleanupDatabase();
				$this->printDebug("Resolving package relations...");
				$this->resolveRelations();
			}

			DB::commit();
		} catch (RuntimeException $e) {
			DB::rollBack();
			$this->printError('UpdatePackages failed:'.$e->getMessage().' at line '.$e->getLine());
		}
	}

	private function prepareQueries() {
		// arches
		$this->selectArchId = DB::prepare('
			SELECT
				id
			FROM
				architectures
			WHERE
				name = :name
			');
		$this->insertArchName = DB::prepare('
			INSERT INTO
				architectures
			SET
				name = :name
			');

		//repos
		$this->selectRepoId = DB::prepare('
			SELECT
				id
			FROM
				repositories
			WHERE
				name = :name
				AND arch = :arch
			');
		$this->insertRepoName = DB::prepare('
			INSERT INTO
				repositories
			SET
				name = :name,
				arch = :arch,
				testing = :testing
			');

		// mtime
		$this->selectRepoMTime = DB::prepare('
			SELECT
				mtime
			FROM
				repositories
			WHERE
				id = :repoId
			');
		$this->updateRepoMTime = DB::prepare('
			UPDATE
				repositories
			SET
				mtime = :mtime
			WHERE
				id = :repoId
			');
		$this->selectPackageMTime = DB::prepare('
			SELECT
				MAX(mtime)
			FROM
				packages
			WHERE
				repository = :repoId
			');

		// packages
		$this->selectPackageId = DB::prepare('
			SELECT
				id
			FROM
				packages
			WHERE
				repository = :repoId
				AND arch = :archId
				AND name = :pkgname
			');
		$this->updatePackage = DB::prepare('
			UPDATE
				packages
			SET
				filename = :filename,
				name = :name,
				base = :base,
				`version` = :version,
				`desc` = :desc,
				csize = :csize,
				isize = :isize,
				md5sum = :md5sum,
				url = :url,
				arch = :arch,
				builddate = :builddate,
				mtime = :mtime,
				packager = :packager,
				repository = :repoId
			WHERE
				id = :id
			');
		$this->insertPackage = DB::prepare('
			INSERT INTO
				packages
			SET
				filename = :filename,
				name = :name,
				base = :base,
				`version` = :version,
				`desc` = :desc,
				csize = :csize,
				isize = :isize,
				md5sum = :md5sum,
				url = :url,
				arch = :arch,
				builddate = :builddate,
				mtime = :mtime,
				packager = :packager,
				repository = :repoId
			');

		// packagers
		$this->selectPackager = DB::prepare('
			SELECT
				id
			FROM
				packagers
			WHERE
				name = :name
				AND email = :email
			');
		$this->insertPackager = DB::prepare('
			INSERT INTO
				packagers
			SET
				name = :name,
				email = :email
			');

		// groups
		$this->selectGroup = DB::prepare('
			SELECT
				id
			FROM
				groups
			WHERE
				name = :name
			');
		$this->insertGroup = DB::prepare('
			INSERT INTO
				groups
			SET
				name = :name
			');
		$this->cleanupPackageGroup = DB::prepare('
			DELETE FROM
				package_group
			WHERE
				package = :package
			');
		$this->insertPackageGroup = DB::prepare('
			INSERT INTO
				package_group
			SET
				package = :package,
				`group` = :group
			');

		// licenses
		$this->selectLicense = DB::prepare('
			SELECT
				id
			FROM
				licenses
			WHERE
				name = :name
			');
		$this->insertLicense = DB::prepare('
			INSERT INTO
				licenses
			SET
				name = :name
			');
		$this->cleanupPackageLicense = DB::prepare('
			DELETE FROM
				package_license
			WHERE
				package = :package
			');
		$this->insertPackageLicense = DB::prepare('
			INSERT INTO
				package_license
			SET
				package = :package,
				license = :license
			');

		// files
		$this->selectFileIndex = DB::prepare('
			SELECT
				id
			FROM
				file_index
			WHERE
				name = :name
			');
		$this->insertFileIndex = DB::prepare('
			INSERT INTO
				file_index
			SET
				name = :name
			');
		$this->cleanupPackageFileIndex = DB::prepare('
			DELETE FROM
				package_file_index
			WHERE
				package = :package
			');
		$this->cleanupFiles = DB::prepare('
			DELETE FROM
				files
			WHERE
				package = :package
			');
		$this->insertFiles = DB::prepare('
			INSERT INTO
				files
			SET
				package = :package,
				path = :path
			');
		$this->insertPackageFileIndex = DB::prepare('
			INSERT INTO
				package_file_index
			SET
				package = :package,
				file_index = :file
			');

		// relations
		$this->cleanupRelation = DB::prepare('
			DELETE FROM
				package_relation
			WHERE
				packageId = :packageId
				AND type = :type
			');
		$this->insertRelation = DB::prepare('
			INSERT INTO
				package_relation
			SET
				packageId = :packageId,
				dependsName = :dependsName,
				dependsVersion =:dependsVersion,
				type = :type
			');
	}

	private function getArchId($archName) {
		if (!isset($this->arches[$archName])) {
			$archHtml = htmlspecialchars($archName);
			$this->selectArchId->bindParam('name', $archHtml, PDO::PARAM_STR);
			$this->selectArchId->execute();
			$id = $this->selectArchId->fetchColumn();
			if ($id === false) {
				$this->insertArchName->bindParam('name', $archHtml, PDO::PARAM_STR);
				$this->insertArchName->execute();
				$id = DB::lastInsertId();
			}
			$this->arches[$archName] = $id;
		}
		return $this->arches[$archName];
	}

	private function getRepoId($repoName, $archId) {
		$repoName = htmlspecialchars($repoName);
		$this->selectRepoId->bindParam('name', $repoName, PDO::PARAM_STR);
		$this->selectRepoId->bindParam('arch', $archId, PDO::PARAM_INT);
		$this->selectRepoId->execute();
		$id = $this->selectRepoId->fetchColumn();
		if ($id === false) {
			$this->insertRepoName->bindParam('name', $repoName, PDO::PARAM_STR);
			$this->insertRepoName->bindParam('arch', $archId, PDO::PARAM_INT);
			$this->insertRepoName->bindValue('testing', (preg_match('/(-|^)testing$/', $repoName) > 0 ? 1 : 0), PDO::PARAM_INT);
			$this->insertRepoName->execute();
			$id = DB::lastInsertId();
		}
		return $id;
	}

	private function getPackagerId($packager) {
		if (!isset($this->packagers[$packager])) {
			preg_match('/([^<>]+)(?:<(.+?)>)?/', $packager, $matches);
			$name = htmlspecialchars(trim(!empty($matches[1]) ? $matches[1] : $packager));
			$email = htmlspecialchars(trim(isset($matches[2]) ? $matches[2] : ''));
			$this->selectPackager->bindParam('name', $name, PDO::PARAM_STR);
			$this->selectPackager->bindParam('email', $email, PDO::PARAM_STR);
			$this->selectPackager->execute();
			$id = $this->selectPackager->fetchColumn();
			if ($id === false) {
				$this->insertPackager->bindParam('name', $name, PDO::PARAM_STR);
				$this->insertPackager->bindParam('email', $email, PDO::PARAM_STR);
				$this->insertPackager->execute();
				$id = DB::lastInsertId();
			}
			$this->packagers[$packager] = $id;
		}
		return $this->packagers[$packager];
	}

	private function addPackageToGroups($packageId, $groups) {
		$this->cleanupPackageGroup->bindParam('package', $packageId, PDO::PARAM_INT);
		$this->cleanupPackageGroup->execute();
		foreach ($groups as $group) {
			$this->insertPackageGroup->bindParam('package', $packageId, PDO::PARAM_INT);
			$this->insertPackageGroup->bindValue('group', $this->getGroupID($group), PDO::PARAM_INT);
			$this->insertPackageGroup->execute();
		}
	}

	private function getGroupID($groupName) {
		if (!isset($this->groups[$groupName])) {
			$htmlGroup = htmlspecialchars($groupName);
			$this->selectGroup->bindParam('name', $htmlGroup, PDO::PARAM_STR);
			$this->selectGroup->execute();
			$id = $this->selectGroup->fetchColumn();
			if ($id === false) {
				$this->insertGroup->bindParam('name', $htmlGroup, PDO::PARAM_STR);
				$this->insertGroup->execute();
				$id = DB::lastInsertId();
			}
			$this->groups[$groupName] = $id;
		}
		return $this->groups[$groupName];
	}

	private function addPackageToLicenses($packageId, $licenses) {
		$this->cleanupPackageLicense->bindParam('package', $packageId, PDO::PARAM_INT);
		$this->cleanupPackageLicense->execute();
		foreach ($licenses as $license) {
			$this->insertPackageLicense->bindParam('package', $packageId, PDO::PARAM_INT);
			$this->insertPackageLicense->bindValue('license', $this->getLicenseID($license), PDO::PARAM_INT);
			$this->insertPackageLicense->execute();
		}
	}

	private function getLicenseID($licenseName) {
		if (!isset($this->licenses[$licenseName])) {
			$htmlLicense = htmlspecialchars($licenseName);
			$this->selectLicense->bindParam('name', $htmlLicense, PDO::PARAM_STR);
			$this->selectLicense->execute();
			$id = $this->selectLicense->fetchColumn();
			if ($id === false) {
				$this->insertLicense->bindParam('name', $htmlLicense, PDO::PARAM_STR);
				$this->insertLicense->execute();
				$id = DB::lastInsertId();
			}
			$this->licenses[$licenseName] = $id;
		}
		return $this->licenses[$licenseName];
	}

	private function addRelation($relations, $packageId, $type) {
		$this->cleanupRelation->bindParam('packageId', $packageId, PDO::PARAM_INT);
		$this->cleanupRelation->bindParam('type', $type, PDO::PARAM_STR);
		$this->cleanupRelation->execute();
		foreach ($relations as $relation) {
			if (preg_match('/^([\w-]+?)((?:<|<=|=|>=|>)+[\w\.:]+)/', $relation, $matches) > 0) {
				$relationName =  htmlspecialchars($matches[1]);
				$relationVersion = htmlspecialchars($matches[2]);
			} elseif (preg_match('/^([\w-]+)/', $relation, $matches) > 0) {
				$relationName =  htmlspecialchars($matches[1]);
				$relationVersion = null;
			} else {
				$relationName = htmlspecialchars($relation);
				$relationVersion = null;
			}
			$this->insertRelation->bindParam('packageId', $packageId, PDO::PARAM_INT);
			$this->insertRelation->bindParam('dependsName', $relationName, PDO::PARAM_STR);
			$this->insertRelation->bindParam('dependsVersion', $relationVersion, PDO::PARAM_STR);
			$this->insertRelation->bindParam('type', $type, PDO::PARAM_STR);
			$this->insertRelation->execute();
		}
	}

	private function getFileIndexID($fileName) {
		if (!isset($this->files[$fileName])) {
			$htmlFile = htmlspecialchars($fileName);
			$this->selectFileIndex->bindParam('name', $htmlFile, PDO::PARAM_STR);
			$this->selectFileIndex->execute();
			$id = $this->selectFileIndex->fetchColumn();
			if ($id === false) {
				$this->insertFileIndex->bindParam('name', $htmlFile, PDO::PARAM_STR);
				$this->insertFileIndex->execute();
				$id = DB::lastInsertId();
			}
			$this->files[$fileName] = $id;
		}
		return $this->files[$fileName];
	}

	private function insertFiles($files, $packageId) {
		$this->cleanupPackageFileIndex->bindParam('package', $packageId, PDO::PARAM_INT);
		$this->cleanupPackageFileIndex->execute();

		$this->cleanupFiles->bindParam('package', $packageId, PDO::PARAM_INT);
		$this->cleanupFiles->execute();

		foreach ($files as $file) {
			$this->insertFiles->bindParam('package', $packageId, PDO::PARAM_INT);
			$this->insertFiles->bindValue('path', mb_substr(htmlspecialchars($file) , 0, 255, 'UTF-8'), PDO::PARAM_STR);
			$this->insertFiles->execute();
			// skip directories (which end with /)
			if (substr($file, -1) != '/') {
				$filename = mb_substr(basename($file) , 0, 100, 'UTF-8');
				if (strlen($filename) > 2) {
					$this->insertPackageFileIndex->bindParam('package', $packageId, PDO::PARAM_INT);
					$this->insertPackageFileIndex->bindValue('file', $this->getFileIndexID($filename), PDO::PARAM_INT);
					$this->insertPackageFileIndex->execute();
				}
			}
		}
	}

	private function updatePackage($repoId, $package) {
		$packageName = htmlspecialchars($package->getName());
		$packageArch = $this->getArchId($package->getArch());

		$this->selectPackageId->bindParam('archId', $packageArch, PDO::PARAM_INT);
		$this->selectPackageId->bindParam('repoId', $repoId, PDO::PARAM_INT);
		$this->selectPackageId->bindParam('pkgname',$packageName, PDO::PARAM_STR);
		$this->selectPackageId->execute();
		$packageId = $this->selectPackageId->fetchColumn();

		if ($packageId !== false) {
			$packageStm = $this->updatePackage;
			$packageStm->bindParam('id', $packageId, PDO::PARAM_INT);
		} else {
			$packageStm = $this->insertPackage;
		}

		$packageStm->bindValue('filename', htmlspecialchars($package->getFileName()), PDO::PARAM_STR);
		$packageStm->bindParam('name', $packageName, PDO::PARAM_STR);
		$packageStm->bindValue('base', htmlspecialchars($package->getBase()), PDO::PARAM_STR);
		$packageStm->bindValue('version', htmlspecialchars($package->getVersion()), PDO::PARAM_STR);
		$packageStm->bindValue('desc', htmlspecialchars($package->getDescription()), PDO::PARAM_STR);
		$packageStm->bindValue('csize', $package->getCompressedSize(), PDO::PARAM_INT);
		$packageStm->bindValue('isize', $package->getInstalledSize(), PDO::PARAM_INT);
		$packageStm->bindValue('md5sum', $package->getMD5SUM(), PDO::PARAM_STR);
		$packageStm->bindValue('url', htmlspecialchars($package->getURL()), PDO::PARAM_STR);
		$packageStm->bindParam('arch', $packageArch, PDO::PARAM_INT);
		$packageStm->bindValue('builddate', $package->getBuildDate(), PDO::PARAM_INT);
		$packageStm->bindValue('mtime', $package->getMTime(), PDO::PARAM_INT);
		$packageStm->bindValue('packager', $this->getPackagerId($package->getPackager()), PDO::PARAM_INT);
		$packageStm->bindParam('repoId', $repoId, PDO::PARAM_INT);
		$packageStm->execute();

		if ($packageId === false) {
			$packageId = DB::lastInsertId();
		}

		$this->addPackageToGroups($packageId, $package->getGroups());
		$this->addPackageToLicenses($packageId, $package->getLicenses());

		$this->addRelation($package->getReplaces(), $packageId, 'replaces');
		$this->addRelation($package->getDepends(), $packageId, 'depends');
		$this->addRelation($package->getOptDepends(), $packageId, 'optdepends');
		$this->addRelation($package->getConflicts(), $packageId, 'conflicts');
		$this->addRelation($package->getProvides(), $packageId, 'provides');

		$this->insertFiles($package->getFiles(), $packageId);

		$this->updatedPackages = true;
// 		echo "\tadding package $packageName\n";
	}

	private function resolveRelations() {
		// Reset all relations
		DB::query('
			UPDATE
				package_relation
			SET
				dependsId = NULL
			');

		// Look for depends within the same repo
		DB::query('
			UPDATE
				package_relation,
				packages,
				packages AS deppkg,
				repositories
			SET
				package_relation.dependsId = deppkg.id
			WHERE
				package_relation.dependsId IS NULL
				AND repositories.id = packages.repository
				AND package_relation.packageId = packages.id
				AND repositories.id = deppkg.repository
				AND deppkg.name = package_relation.dependsName
			');

		// Look for depends in other repos except testing repos
		DB::query('
			UPDATE
				package_relation,
				packages,
				packages AS deppkg,
				repositories,
				repositories AS deprepo
			SET
				package_relation.dependsId = deppkg.id
			WHERE
				package_relation.dependsId IS NULL
				AND repositories.arch = deprepo.arch
				AND repositories.id = packages.repository
				AND package_relation.packageId = packages.id
				AND deprepo.id = deppkg.repository
				AND deprepo.testing = 0
				AND deppkg.name = package_relation.dependsName
			');
	}

	private function cleanupObsoletePackages($repoId, $packageMTime, $allPackages) {
		$cleanupPackages = DB::prepare('
			DELETE FROM
				packages
			WHERE
				id = :packageId
			');
		$cleanupRelations = DB::prepare('
			DELETE FROM
				package_relation
			WHERE
				packageId = :packageId
			');
		$cleanupFiles = DB::prepare('
			DELETE FROM
				files
			WHERE
				package = :packageId
			');
		$cleanupPackageFileIndex = DB::prepare('
			DELETE FROM
				package_file_index
			WHERE
				package = :packageId
			');
		$cleanupPackageGroup = DB::prepare('
			DELETE FROM
				package_group
			WHERE
				package = :packageId
			');
		$cleanupPackageLicense = DB::prepare('
			DELETE FROM
				package_license
			WHERE
				package = :packageId
			');
		$repoPackages = DB::prepare('
			SELECT
				id,
				name
			FROM
				packages
			WHERE
				repository = :repoId
				AND mtime <= :mtime
			');
		$repoPackages->bindValue('repoId', $repoId, PDO::PARAM_INT);
		$repoPackages->bindValue('mtime', $packageMTime, PDO::PARAM_INT);
		$repoPackages->execute();
		foreach ($repoPackages as $repoPackage) {
			if (!in_array($repoPackage['name'], $allPackages)) {
// 				echo "\tremoving package $repoPackage[name]\n";
				$cleanupPackages->bindValue('packageId', $repoPackage['id'], PDO::PARAM_INT);
				$cleanupPackages->execute();
				$cleanupRelations->bindValue('packageId', $repoPackage['id'], PDO::PARAM_INT);
				$cleanupRelations->execute();
				$cleanupFiles->bindValue('packageId', $repoPackage['id'], PDO::PARAM_INT);
				$cleanupFiles->execute();
				$cleanupPackageFileIndex->bindValue('packageId', $repoPackage['id'], PDO::PARAM_INT);
				$cleanupPackageFileIndex->execute();
				$cleanupPackageGroup->bindValue('packageId', $repoPackage['id'], PDO::PARAM_INT);
				$cleanupPackageGroup->execute();
				$cleanupPackageLicense->bindValue('packageId', $repoPackage['id'], PDO::PARAM_INT);
				$cleanupPackageLicense->execute();
				$this->updatedPackages = true;
			}
		}
	}

	private function cleanupObsoleteRepositories() {
		$repos = DB::prepare('
			SELECT
				repositories.id,
				repositories.name,
				architectures.name AS arch
			FROM
				repositories
				JOIN architectures
				ON architectures.id = repositories.arch
			')->fetchAll();
		$configRepos = Config::get('packages', 'repositories');
		foreach ($repos as $repo) {
			if (!isset($configRepos[$repo['name']])
				|| !in_array($repo['arch'], $configRepos[$repo['name']])) {
				$this->cleanupObsoletePackages($repo['id'], time(), array());
				DB::query('
					DELETE FROM
						repositories
					WHERE
						id = '.$repo['id'].'
					');
				$this->updatedPackages = true;
			}
		}
	}

	private function cleanupDatabase() {
		DB::query('
			DELETE FROM
				groups
			WHERE
				id NOT IN (SELECT package_group.group FROM package_group)
			');
		DB::query('
			DELETE FROM
				licenses
			WHERE
				id NOT IN (SELECT license FROM package_license)
			');
		DB::query('
			DELETE FROM
				packagers
			WHERE
				id NOT IN (SELECT packager FROM packages)
			');
		DB::query('
			DELETE FROM
				architectures
			WHERE
				id NOT IN (SELECT arch FROM packages)
			');
		DB::query('
			DELETE FROM
				file_index
			WHERE
				id NOT IN (SELECT file_index FROM package_file_index)
			');
	}
}

UpdatePackages::run();

?>
