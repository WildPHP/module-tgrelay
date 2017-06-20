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

	/**
	 * @var string
	 */
	protected $baseURI;

	public function __construct(ComponentContainer $container, int $port, string $listenOn, string $baseURI)
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
		$this->setBaseURI($baseURI);
	}

	/**
	 * @param string $chatID
	 *
	 * @return string	 *
	 */
	public function makeFileStructure(string $chatID): string
	{
		$basePath = WPHP_ROOT_DIR . '/tgstorage';
		$idHash = sha1($chatID);

		$structure = [
			$basePath,
			$basePath . '/' . $idHash,
			$basePath . '/' . $idHash . '/photos',
			$basePath . '/' . $idHash . '/documents',
			$basePath . '/' . $idHash . '/animations',
			$basePath . '/' . $idHash . '/stickers',
			$basePath . '/' . $idHash . '/video',
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
	 * @param string $chatID
	 * @param string $data
	 *
	 * @return false|string
	 */
	public function putData(string $path, string $chatID, string $data)
	{
		$basePath = $this->makeFileStructure($chatID);
		$path = $basePath . '/' . $path;

		if (!@touch($path))
			return false;

		if (!@file_put_contents($path, $data))
			return false;

		return $this->getBaseURI() . '/' . sha1($chatID) . '/' . $path;
	}

	/**
	 * @param string $path
	 * @param string $chatID
	 *
	 * @return string
	 */
	public function getFileUrl(string $path, string $chatID): string
	{

	}

	/**
	 * @return string
	 */
	public function getBaseURI(): string
	{
		return $this->baseURI;
	}

	/**
	 * @param string $baseURI
	 */
	public function setBaseURI(string $baseURI)
	{
		$this->baseURI = $baseURI;
	}
}