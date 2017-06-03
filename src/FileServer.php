<?php
/**
 * WildPHP - an advanced and easily extensible IRC bot written in PHP
 * Copyright (C) 2017 WildPHP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 27-5-2017
 * Time: 14:25
 */

namespace WildPHP\Modules\TGRelay;


use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use React\Socket\Server;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\Logger\Logger;

class FileServer
{
	use ContainerTrait;

	public function __construct(ComponentContainer $container, int $port, string $listenOn)
	{
		$this->setContainer($container);
		$socket = new Server($listenOn . ':' . $port, $container->getLoop());

		$http = new \React\Http\Server($socket, function (ServerRequestInterface $request) {
			$path = $request->getUri()->getPath();
			$path = WPHP_ROOT_DIR . 'tgstorage/' . $path;

			if (!file_exists($path) || is_dir($path))
				return new Response(404, array('Content-Type' => 'text/plain'), '404: Not Found');

			return new Response(
				200,
				array('Content-Type' => mime_content_type($path)),
				file_get_contents($path)
			);
		});
	}

	/**
	 * @param string $idHash
	 *
	 * @return string
	 */
	public function makeFileStructure(string $idHash): string
	{
		$basePath = WPHP_ROOT_DIR . '/tgstorage';

		$structure = [
			$basePath,
			$basePath . '/' . $idHash,
			$basePath . '/' . $idHash . '/photos',
			$basePath . '/' . $idHash . '/documents',
			$basePath . '/' . $idHash . '/animations',
			$basePath . '/' . $idHash . '/stickers',
			$basePath . '/' . $idHash . '/voice'
		];

		foreach ($structure as $item)
		{
			if (!file_exists($item))
				mkdir($item);
		}

		return $basePath . '/' . $idHash;
	}

	public function downloadFileAsync(string $path, string $botID, string $hashID, string &$fileURIPath = ''): PromiseInterface
	{
		$file_url = 'https://api.telegram.org/file/bot' . $botID . '/' . $path;

		$basedir = $this->makeFileStructure($hashID);
		$filePath = $basedir . '/' . $path;

		Logger::fromContainer($this->getContainer())->debug('[TG] Downloading file', [
			'uri' => $file_url,
			'path' => $filePath
		]);

		touch($filePath);
		$fileResource = fopen($filePath, 'w');
		$guzzleClient = new Client([
			'connect_timeout' => 3.0,
			'timeout' => 3.0
		]);
		$fileURIPath = $hashID . '/' . $path;
		return $guzzleClient->requestAsync('GET', $file_url, ['sink' => $fileResource, 'curl' => [CURLOPT_SSL_VERIFYPEER => false]]);
	}
}