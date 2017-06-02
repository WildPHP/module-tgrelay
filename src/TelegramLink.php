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