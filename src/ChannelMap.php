<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use WildPHP\Core\Collection;

class ChannelMap extends Collection
{
	public function __construct()
	{
		parent::__construct(TelegramLink::class);
	}

	/**
	 * @param string $channel
	 *
	 * @return bool|float|int|string
	 */
	public function findIDForChannel(string $channel)
	{
		/** @var TelegramLink $value */
		foreach ($this->values() as $value)
			if ($value->getChannel() == $channel)
				$link = $value;

		if (empty($link))
			return false;

		return $link->getChatID();
	}

	/**
	 * @param $id
	 *
	 * @return bool|string
	 */
	public function findChannelForID($id)
	{
		/** @var TelegramLink $value */
		foreach ($this->values() as $value)
			if ($value->getChatID() == $id)
				$link = $value;

		if (empty($link))
			return false;

		return $link->getChannel();
	}
}