<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;


use unreal4u\TelegramAPI\Telegram\Types\File;

class DownloadedFile
{
	/**
	 * @var string
	 */
	protected $body = '';

	/**
	 * @var File
	 */
	protected $file;

	public function __construct(string $body, File $file)
	{
		$this->body = $body;
		$this->file = $file;
	}

	/**
	 * @return string
	 */
	public function getBody(): string
	{
		return $this->body;
	}

	/**
	 * @return File
	 */
	public function getFile(): File
	{
		return $this->file;
	}
}