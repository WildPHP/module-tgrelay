<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

/**
 * Created by PhpStorm.
 * User: rick2
 * Date: 27-5-2017
 * Time: 14:25
 */

namespace WildPHP\Modules\TGRelay;


use GuzzleHttp\Client;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use React\Socket\Server;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\Logger\Logger;

class FileServer
{
	use ContainerTrait;

	public function __construct(ComponentContainer $container, int $port, string $listenOn)
	{
		$this->setContainer($container);
		$socket = new Server($listenOn . ':' . $port, $container->getLoop());

		$http = new \React\Http\Server(function (ServerRequestInterface $request) {
			$path = $request->getUri()->getPath();
			$path = WPHP_ROOT_DIR . 'tgstorage/' . $path;

			if (!file_exists($path) || is_dir($path))
				return new Response(404, ['Content-Type' => 'text/plain'], '404: Not Found');

			return new Response(
				200,
				['Content-Type' => mime_content_type($path)],
				file_get_contents($path)
			);
		});
		$http->listen($socket);
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

	/**
	 * @param string $path
	 * @param string $botID
	 * @param string $hashID
	 * @param string $fileURIPath
	 *
	 * @return mixed|\Psr\Http\Message\ResponseInterface
	 *
	 * TODO make this truly async
	 */
	public function downloadFileAsync(string $path, string $botID, string $hashID, string &$fileURIPath = '')
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
		return $guzzleClient->request('GET', $file_url, ['sink' => $fileResource, 'curl' => [CURLOPT_SSL_VERIFYPEER => false]]);
	}
}