<?php
/*
	Copyright 2002-2007 Pierre Schmitz <pschmitz@laber-land.de>

	This file is part of LL.

	LL is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	LL is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with LL.  If not, see <http://www.gnu.org/licenses/>.
*/

class GetFileFromMirror extends Page{

public function prepare()
	{
	$this->setValue('title', 'Lade Datei von einem Spiegel-Server');

	try
		{
		$file = htmlspecialchars($this->Io->getString('file'));
		}
	catch (IoRequestException $e)
		{
		$this->showFailure('keine Datei angegeben!');
		}

	if (count($this->Settings->getValue('mirrors')) == 0)
		{
		$this->showFailure('keine Spiegel-Server gefunden!');
		}

	$this->setValue('title', basename($file));

	$mirror = $this->getRandomMirror($file);
	$url = $mirror.$file;

	$body = '<div id="box">
			<h2>'.basename($file).'</h2>
			<p>Aktueller Server: <strong><a href="'.$url.'">'.$mirror.'</a></strong></p>
			Alternative Server:'.$this->getAlternateMirrorList($url, $file).'
		</div>
		<script type="text/javascript">
			/* <![CDATA[ */
			setTimeout(\'location.href="'.$url.'"\', 4000);
			/* ]]> */
		</script>';

	$this->setValue('body', $body);
	}

private function getAlternateMirrorList($url, $file)
	{
	$list = '<ul>';
	$mirrors = $this->Settings->getValue('mirrors');
	arsort($mirrors);

	foreach ($mirrors as $mirror => $probability)
		{
		if ($probability == 0 || $mirror.$file == $url)
			{
			continue;
			}
		$list .= '<li><a href="'.$mirror.$file.'">'.$mirror.'</a></li>';
		}

	return $list.'</ul>';
	}

private function getRandomMirror($file)
	{
	$tempMirrors = array();

	foreach ($this->Settings->getValue('mirrors') as $mirror => $probability)
		{
		for ($i = 0; $i < $probability; $i++)
			{
			$tempMirrors[] = $mirror;
			}
		}

	$randomIndex = array_rand($tempMirrors);

	return $tempMirrors[$randomIndex];
	}

}

?>