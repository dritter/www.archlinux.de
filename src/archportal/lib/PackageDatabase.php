<?php

/*
  Copyright 2002-2015 Pierre Schmitz <pierre@archlinux.de>

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

namespace archportal\lib;

use Iterator;
use RuntimeException;

class PackageDatabase implements Iterator
{
    /** @var string */
    private $dbext = '.db';
    /** @var int */
    private $mtime = 0;
    /** @var int */
    private $repoMinMTime = 0;
    /** @var int */
    private $packageMinMTime = 0;
    /** @var int */
    private $currentKey = 0;
    /** @var bool */
    private $currentDir = false;
    private $dbHandle = null;
    /** @var null|string */
    private $dbDir = null;
    /** @var null|int */
    private $packageCount = null;

    /**
     * @param string $repository
     * @param string $architecture
     * @param int    $repoMinMTime
     * @param int    $packageMinMTime
     */
    public function __construct(
        string $repository,
        string $architecture,
        int $repoMinMTime = 0,
        int $packageMinMTime = 0
    ) {
        if (Config::get('packages', 'files')) {
            $this->dbext = '.files';
        }
        $this->repoMinMTime = $repoMinMTime;
        $this->packageMinMTime = $packageMinMTime;
        $download = new Download(Config::get('packages',
                'mirror').$repository.'/os/'.$architecture.'/'.$repository.$this->dbext);
        $this->mtime = $download->getMTime();

        $this->dbDir = $this->makeTempDir();
        $this->dbHandle = opendir($this->dbDir);

        if ($this->mtime > $this->repoMinMTime && Input::getTime() - $this->mtime > Config::get('packages', 'delay')) {
            system('bsdtar -xf '.$download->getFile().' -C '.$this->dbDir, $return);
            if ($return !== 0) {
                throw new RuntimeException('Could not extract Database');
            }
        }
    }

    /**
     * @return string
     */
    private function makeTempDir(): string
    {
        $tmp = tempnam(Config::get('common', 'tmpdir'), strtolower(str_replace('\\', '/', get_class($this))));
        unlink($tmp);
        mkdir($tmp, 0700);

        return $tmp;
    }

    public function __destruct()
    {
        closedir($this->dbHandle);
        if (is_dir($this->dbDir)) {
            $this->rmrf($this->dbDir);
        }
    }

    /**
     * @return Package
     */
    public function current(): Package
    {
        return new Package($this->dbDir.'/'.$this->currentDir);
    }

    /**
     * @return int
     */
    public function key(): int
    {
        return $this->currentKey;
    }

    public function next()
    {
        do {
            $this->currentDir = readdir($this->dbHandle);
        } while ($this->currentDir == '.' || $this->currentDir == '..' || filemtime($this->dbDir.'/'.$this->currentDir) <= $this->packageMinMTime
        );
        ++$this->currentKey;
    }

    public function rewind()
    {
        rewinddir($this->dbHandle);
        $this->currentKey = 0;
        $this->currentDir = false;
        $this->next();
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return $this->currentDir !== false;
    }

    /**
     * @return int
     */
    public function getMTime(): int
    {
        return $this->mtime;
    }

    /**
     * @param string $dir
     *
     * @return bool
     */
    private function rmrf(string $dir): bool
    {
        if (is_dir($dir) && !is_link($dir)) {
            $dh = opendir($dir);
            while (false !== ($file = readdir($dh))) {
                if ($file != '.' && $file != '..') {
                    if (!$this->rmrf($dir.'/'.$file)) {
                        throw new RuntimeException('Could not remove '.$dir.'/'.$file);
                    }
                }
            }
            closedir($dh);

            return rmdir($dir);
        } else {
            return unlink($dir);
        }
    }

    /**
     * @return int
     */
    public function getNewPackageCount(): int
    {
        if (is_null($this->packageCount)) {
            $packages = 0;
            if (is_dir($this->dbDir)) {
                $dh = opendir($this->dbDir);
                while (false !== ($dir = readdir($dh))) {
                    if (is_dir($this->dbDir.'/'.$dir) && $dir != '.' && $dir != '..' && filemtime($this->dbDir.'/'.$dir) > $this->packageMinMTime
                    ) {
                        ++$packages;
                    }
                }
                closedir($dh);
            }
            $this->packageCount = $packages;
        }

        return $this->packageCount;
    }

    /**
     * @return array
     */
    public function getOldPackageNames(): array
    {
        $packages = array();
        if (is_dir($this->dbDir)) {
            $dh = opendir($this->dbDir);
            while (false !== ($dir = readdir($dh))) {
                if (is_dir($this->dbDir.'/'.$dir) && $dir != '.' && $dir != '..' && filemtime($this->dbDir.'/'.$dir) <= $this->packageMinMTime
                ) {
                    $matches = array();
                    if (preg_match('/^([^\-].*)-[^\-]+?-[^\-]+?$/', $dir, $matches) == 1) {
                        $packages[] = $matches[1];
                    } else {
                        throw new RuntimeException('Could not read package '.$dir);
                    }
                }
            }
            closedir($dh);
        }

        return $packages;
    }
}
