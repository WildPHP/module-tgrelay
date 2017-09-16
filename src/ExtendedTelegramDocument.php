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
 * Date: 28-7-2017
 * Time: 15:27
 */

namespace WildPHP\Modules\TGRelay;


use unreal4u\TelegramAPI\InternalFunctionality\TelegramDocument;

class ExtendedTelegramDocument extends TelegramDocument
{
	/**
	 * @var string
	 */
	protected $path;

	/**
	 * @var string
	 */
	protected $uri;

	/**
	 * ExtendedTelegramDocument constructor.
	 *
	 * @param TelegramDocument $response
	 * @param string $uri
	 * @param string $path
	 */
	public function __construct(TelegramDocument $response, string $uri, string $path)
	{
		$this->contents = $response->contents;
		$this->file_size = $response->file_size;
		$this->mime_type = $response->mime_type;
		$this->uri = $uri;
		$this->path = $path;
	}

	/**
	 * @return string
	 */
	public function getPath(): string
	{
		return $this->path;
	}

	/**
	 * @return string
	 */
	public function getUri(): string
	{
		return $this->uri;
	}
}