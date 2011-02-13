#!/usr/bin/php
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

class UpdateFileDB extends CronJob {

	private $mirror = 'http://mirrors.kernel.org/archlinux/';
	private $curmtime = array();
	private $lastmtime = array();
	private $changed = false;

	public function execute() {
		$this->mirror = Config::get('packages', 'mirror');
		try {
			DB::beginTransaction();
			foreach (Config::get('packages', 'repositories') as $repo) {
				foreach (Config::get('packages', 'architectures') as $arch) {
					if (strpos($repo, 'multilib') === 0 && $arch != 'x86_64') {
						continue;
					}
					$this->updateFiles($repo, $arch);
				}
			}
			if ($this->changed) {
				$this->removeUnusedEntries();
				RepositoryStatistics::updateDBCache();
			}
			DB::commit();
		} catch (RuntimeException $e) {
			DB::rollBack();
			echo 'UpdateFileDB failed:'.$e->getMessage();
		}
	}

	private function setLogEntry($name, $time) {
		$stm = DB::prepare('
		REPLACE INTO
			log
		SET
			name = :name,
			time = :time
		');
		$stm->bindParam('name', $name, PDO::PARAM_STR);
		$stm->bindParam('time', $time, PDO::PARAM_INT);
		$stm->execute();
	}

	private function getLogEntry($name) {
		$stm = DB::prepare('
		SELECT
			time
		FROM
			log
		WHERE
			name = :name
		');
		$stm->bindParam('name', $name, PDO::PARAM_STR);
		$stm->execute();
		return $stm->fetchColumn() ?: 0;
	}

	private function setCurMTime($repo, $arch, $mtime) {
		if (!isset($this->curmtime["$repo-$arch"])) {
			$this->curmtime["$repo-$arch"] = $mtime;
		} elseif ($mtime > $this->curmtime["$repo-$arch"]) {
			$this->curmtime["$repo-$arch"] = $mtime;
		}
	}

	private function setLastMTime($repo, $arch, $mtime) {
		if (!isset($this->lastmtime["$repo-$arch"])) {
			$this->lastmtime["$repo-$arch"] = $mtime;
		} elseif ($mtime > $this->lastmtime["$repo-$arch"]) {
			$this->lastmtime["$repo-$arch"] = $mtime;
		}
	}

	private function getCurMTime($repo, $arch) {
		if (isset($this->curmtime["$repo-$arch"])) {
			return $this->curmtime["$repo-$arch"];
		} else {
			return 0;
		}
	}

	private function getLastMTime($repo, $arch) {
		if (isset($this->lastmtime["$repo-$arch"])) {
			return $this->lastmtime["$repo-$arch"];
		} else {
			return 0;
		}
	}

	private function updateFiles($repo, $arch) {
		$download = new Download($this->mirror.$repo.'/os/'.$arch.'/'.$repo.'.files.tar.gz');
		$mtime = $download->getMTime();

		if ($mtime > $this->getLogEntry('UpdateFileDB-mtime-' . $repo . '-' . $arch)) {
			$this->setLastMTime($repo, $arch, $this->getLogEntry('UpdateFileDB-' . $repo . '-' . $arch));
			$this->changed = true;
			$dbDir = tempnam(Config::get('common', 'tmpdir') . '/', $arch . '-' . $repo . '-files.db-');
			unlink($dbDir);
			mkdir($dbDir, 0700);
			exec('bsdtar -xf '.$download->getFile().' -C '.$dbDir, $output, $return);

			if ($return == 0) {
				$dh = opendir($dbDir);
				while (false !== ($dir = readdir($dh))) {
					if ($dir != '.'
						&& $dir != '..'
						&& file_exists($dbDir . '/' . $dir . '/files')
						&& filemtime($dbDir . '/' . $dir . '/files') >= $this->getLastMTime($repo, $arch)) {
						$this->insertFiles($repo, $arch, $dir,
							file($dbDir.'/'.$dir.'/files', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
						$this->setCurMTime($repo, $arch, filemtime($dbDir . '/' . $dir . '/files'));
					}
				}
				closedir($dh);
				$this->rmrf($dbDir);
				$this->setLogEntry('UpdateFileDB-' . $repo . '-' . $arch, $this->getCurMTime($repo, $arch));
				$this->setLogEntry('UpdateFileDB-mtime-' . $repo . '-' . $arch, $mtime);
			} else {
				$this->rmrf($dbDir);
			}
		}
	}

	private function insertFiles($repo, $arch, $package, $files) {
		$pkgid = $this->getPackageID($repo, $arch, $package);
		$stm = DB::prepare('
		DELETE FROM
			package_file_index
		WHERE
			package = :package
		');
		$stm->bindParam('package', $pkgid, PDO::PARAM_INT);
		$stm->execute();

		$stm = DB::prepare('
		DELETE FROM
			files
		WHERE
			package = :package
		');
		$stm->bindParam('package', $pkgid, PDO::PARAM_INT);
		$stm->execute();

		$stm1 = DB::prepare('
		INSERT INTO
			files
		SET
			package = :package,
			path = :path
		');

		$stm2 = DB::prepare('
		INSERT INTO
			package_file_index
		SET
			package = :package,
			file_index = :file
		');

		for ($file = 1;$file < count($files);$file++) {
			$stm1->bindParam('package', $pkgid, PDO::PARAM_INT);
			$stm1->bindValue('path', mb_substr(htmlspecialchars($files[$file]) , 0, 255, 'UTF-8'), PDO::PARAM_STR);
			$stm1->execute();
			$filename = mb_substr(htmlspecialchars(basename($files[$file])) , 0, 100, 'UTF-8');
			if (strlen($filename) > 2) {
				$stm2->bindParam('package', $pkgid, PDO::PARAM_INT);
				$stm2->bindValue('file', $this->getFileIndexID($filename), PDO::PARAM_INT);
				$stm2->execute();
			}
		}
	}

	private function getFileIndexID($file) {
		$stm = DB::prepare('
		SELECT
			id
		FROM
			file_index
		WHERE
			name = :name
		');
		$stm->bindParam('name', $file, PDO::PARAM_STR);
		$stm->execute();
		$id = $stm->fetchColumn();

		if ($id === false) {
			$stm = DB::prepare('
			INSERT INTO
				file_index
			SET
				name = :name
			');
			$stm->bindParam('name', $file, PDO::PARAM_STR);
			$stm->execute();
			$id = DB::lastInsertId();
		}

		return $id;
	}

	private function getPackageID($repo, $arch, $package) {
		$stm = DB::prepare('
		SELECT
			packages.id
		FROM
			packages,
			architectures,
			repositories
		WHERE
			packages.name = :package
			AND repositories.name = :repository
			AND architectures.name = :architecture
			AND packages.arch = architectures.id
			AND packages.repository = repositories.id
		');
		$stm->bindValue('package', htmlspecialchars(preg_replace('/^(.+)-.+?-.+?$/', '$1', $package)), PDO::PARAM_STR);
		$stm->bindValue('repository', htmlspecialchars($repo), PDO::PARAM_STR);
		$stm->bindValue('architecture', htmlspecialchars($arch), PDO::PARAM_STR);
		$stm->execute();
		return $stm->fetchColumn();
	}

	private function removeUnusedEntries() {
		DB::query('
		DELETE FROM
			file_index
		WHERE
			id NOT IN (SELECT file_index FROM package_file_index)
		');
	}

	private function rmrf($dir) {
		if (is_dir($dir) && !is_link($dir)) {
			$dh = opendir($dir);
			while (false !== ($file = readdir($dh))) {
				if ($file != '.' && $file != '..') {
					if (!$this->rmrf($dir . '/' . $file)) {
						trigger_error('Could not remove ' . $dir . '/' . $file);
					}
				}
			}
			closedir($dh);
			return rmdir($dir);
		} else {
			return unlink($dir);
		}
	}
}

UpdateFileDB::run();

?>
