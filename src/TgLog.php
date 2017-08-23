<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use Yoshi2889\Container\ComponentInterface;
use Yoshi2889\Container\ComponentTrait;

/**
 * Class TgLog
 * @package WildPHP\Modules\TGRelay
 *
 * Simple wrapper around the TgLog object, to allow it to sit in a container.
 */
class TgLog extends \unreal4u\TelegramAPI\TgLog implements ComponentInterface
{
	use ComponentTrait;

	/**
	 * @param string $chatID
	 *
	 * @return string
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
			$basePath . '/' . $idHash . '/videos',
			$basePath . '/' . $idHash . '/voice'
		];

		foreach ($structure as $item)
		{
			if (!file_exists($item))
				mkdir($item);
		}

		return $basePath . '/' . $idHash;
	}
}