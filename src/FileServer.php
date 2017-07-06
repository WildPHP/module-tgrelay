<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Response;
use React\Socket\Server;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\ContainerTrait;

class FileServer
{
	use ContainerTrait;

	/**
	 * FileServer constructor.
	 *
	 * @param ComponentContainer $container
	 * @param int $port
	 * @param string $listenOn
	 */
	public function __construct(ComponentContainer $container, int $port, string $listenOn)
	{
		$this->setContainer($container);
		$socket = new Server($listenOn . ':' . $port, $container->getLoop());

		$http = new \React\Http\Server(function (ServerRequestInterface $request) use ($container)
		{
			$path = $request->getUri()
				->getPath();
			$path = WPHP_ROOT_DIR . 'tgstorage' . $path;

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
}