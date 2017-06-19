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
 * Time: 17:20
 */

namespace WildPHP\Modules\TGRelay;


class TelegramLink
{
	/**
	 * @var int|float
	 */
	protected $chatID;

	/**
	 * @var string
	 */
	protected $channel = '';

	/**
	 * @return float|int
	 */
	public function getChatID()
	{
		return $this->chatID;
	}

	/**
	 * @param float|int $chatID
	 */
	public function setChatID($chatID)
	{
		$this->chatID = $chatID;
	}

	/**
	 * @return string
	 */
	public function getChannel(): string
	{
		return $this->channel;
	}

	/**
	 * @param string $channel
	 */
	public function setChannel(string $channel)
	{
		$this->channel = $channel;
	}
}