<?php


namespace R3ct4lan\DiscountComparison\Providers;


use Bitrix\Sale\Discount\Formatter;
use CCurrencyRates;

/**
 * Class Actions
 * Переопределяет функционал применения скидки к корзине.
 * Функция applyToBasketItem позволяет применить скидку только на одну позицию в корзине.
 * @package R3ct4lan\DiscountComparison
 */
class Actions extends \Bitrix\Sale\Discount\Actions
{

	/**
	 * Применение скидки к одному элементу корзины
	 * @param array &$order Order data.
	 * @param array $currentItem Товар, на который должна примениться скидка
	 * @param array $action Action detail
	 * @param callable $filter Filter for basket items.
	 * @return void
	 * @see \Bitrix\Sale\Discount\Actions::applyToBasket
	 */
	public static function applyToBasketItem(array &$order, $currentItem, array $action, $filter)
	{
		static::increaseApplyCounter(); //увеличивает количество примененных скидок

		if (!isset($action['VALUE']) || !isset($action['UNIT'])) //проверяет что ПРСК валидно
			return;

		$orderCurrency = static::getCurrency(); //валюта заказа
		$value = (float)$action['VALUE']; //величина скидки
		$limitValue = (int)$action['LIMIT_VALUE']; //максимальная стоимость скидки
		$unit = (string)$action['UNIT']; //тип скидки - процент или стоимость
		$currency = (isset($action['CURRENCY']) ? $action['CURRENCY'] : $orderCurrency); //валюта скидки
		$maxBound = false; //разрешить устанавливать цену в 0, если скидка больше цены
		if ($unit == self::VALUE_TYPE_FIX && $value < 0)
			$maxBound = (isset($action['MAX_BOUND']) && $action['MAX_BOUND'] == 'Y');
		$valueAction = ( //скидка или наценка
		$value < 0
			? Formatter::VALUE_ACTION_DISCOUNT
			: Formatter::VALUE_ACTION_EXTRA
		);

		$actionDescription = array( //описание ПРСК
			'ACTION_TYPE' => Formatter::TYPE_VALUE,
			'VALUE' => abs($value),
			'VALUE_ACTION' => $valueAction
		);
		switch ($unit) //тип скидки
		{
			case self::VALUE_TYPE_SUMM:
				$actionDescription = [
					'ACTION_TYPE' => Formatter::TYPE_VALUE,
					'VALUE' => abs($value),
					'VALUE_ACTION' => ($value < 0 ? Formatter::VALUE_ACTION_DISCOUNT : Formatter::VALUE_ACTION_EXTRA),
					'VALUE_TYPE' => Formatter::VALUE_TYPE_SUMM,
					'VALUE_UNIT' => $currency
				];
				break;
			case self::VALUE_TYPE_PERCENT: //процентная скидка
				$actionDescription = [
					'ACTION_TYPE' => Formatter::TYPE_VALUE,
					'VALUE' => abs($value),
					'VALUE_ACTION' => ($value < 0 ? Formatter::VALUE_ACTION_DISCOUNT : Formatter::VALUE_ACTION_EXTRA),
					'VALUE_TYPE' => Formatter::VALUE_TYPE_PERCENT
				];
				break;
			case self::VALUE_TYPE_FIX: //"рублёвая" скидка
				$actionDescription = [
					'ACTION_TYPE' => ($maxBound ? Formatter::TYPE_MAX_BOUND : Formatter::TYPE_VALUE),
					'VALUE' => abs($value),
					'VALUE_ACTION' => ($value < 0 ? Formatter::VALUE_ACTION_DISCOUNT : Formatter::VALUE_ACTION_EXTRA),
					'VALUE_TYPE' => Formatter::VALUE_TYPE_CURRENCY,
					'VALUE_UNIT' => $currency
				];
				break;
			case self::VALUE_TYPE_CLOSEOUT:
				$actionDescription = [
					'ACTION_TYPE' => Formatter::TYPE_FIXED,
					'VALUE' => abs($value),
					'VALUE_ACTION' => Formatter::VALUE_ACTION_DISCOUNT,
					'VALUE_TYPE' => Formatter::VALUE_TYPE_CURRENCY,
					'VALUE_UNIT' => $currency
				];
				break;
			default:
				return;
				break;
		}
		$valueAction = $actionDescription['VALUE_ACTION'];

		if (!empty($limitValue)) //есть ограничение по величине ПРСК
		{
			$actionDescription['ACTION_TYPE'] = Formatter::TYPE_LIMIT_VALUE;
			$actionDescription['LIMIT_TYPE'] = Formatter::LIMIT_MAX;
			$actionDescription['LIMIT_UNIT'] = $orderCurrency;
			$actionDescription['LIMIT_VALUE'] = $limitValue;
		}

		//сохраняет описание ПРСК
		static::setActionDescription(self::RESULT_ENTITY_BASKET, $actionDescription);

		if (empty($order['BASKET_ITEMS']) || !is_array($order['BASKET_ITEMS'])) //в корзине нет товаров
			return;

		static::enableBasketFilter(); //включает фильтр корзины по условиям ПРСК
		$filteredBasket = static::getBasketForApply($order['BASKET_ITEMS'], $filter, $action); //получает корзину
		if (empty($filteredBasket)) //нет подходящих товаров
			return;

		//фильтрует корзину по условиям ПРСК
		$applyBasket = array_filter($filteredBasket, '\Bitrix\Sale\Discount\Actions::filterBasketForAction');
		unset($filteredBasket);
		if (empty($applyBasket)) //нет подходящих товаров
			return;

		if ($unit == self::VALUE_TYPE_SUMM || $unit == self::VALUE_TYPE_FIX) //если скидка в рублях
		{
			if ($currency != $orderCurrency)
				$value = CCurrencyRates::ConvertCurrency($value, $currency, $orderCurrency); //приводит сумму в формат валюты заказа
			if ($unit == self::VALUE_TYPE_SUMM) {
				$value = static::getPercentByValue($applyBasket, $value); //считает процент из рублевой скидки
				if ( //если применяемая скидка не верна
					($valueAction == Formatter::VALUE_ACTION_DISCOUNT && ($value >= 0 || $value < -100))
					||
					($valueAction == Formatter::VALUE_ACTION_EXTRA && $value <= 0)
				)
					return; //фатал
				$unit = self::VALUE_TYPE_PERCENT; //теперь скидка процентная
			}
		}
		
		$value = static::roundZeroValue($value); //проверяет, не является ли применяемая скидка меньше, чем минимальное значение
		
		if ($value == 0)
			return;

		//перебор всех товаров
		foreach ($applyBasket as $basketCode => $basketRow) {
			// Пропуск остальных товаров
			if ($basketRow['ID'] !== $currentItem['ID'])
				continue;

			//получение величины скидки
			list($calculateValue, $result) = self::calculateDiscountPrice(
				$value,
				$unit,
				$basketRow,
				$limitValue,
				$maxBound
			);
			
			if ($result >= 0) {
				self::fillDiscountPrice($basketRow, $result, -$calculateValue); //установка на товар новой цены со скидкой

				$order['BASKET_ITEMS'][$basketCode] = $basketRow;

				//заполнение описания примененной скидки
				$rowActionDescription = $actionDescription;
				$rowActionDescription['BASKET_CODE'] = $basketCode;
				$rowActionDescription['RESULT_VALUE'] = abs($calculateValue);
				$rowActionDescription['RESULT_UNIT'] = $orderCurrency;

				if (!empty($limitValue)) {
					$rowActionDescription['ACTION_TYPE'] = Formatter::TYPE_LIMIT_VALUE;
					$rowActionDescription['LIMIT_TYPE'] = Formatter::LIMIT_MAX;
					$rowActionDescription['LIMIT_UNIT'] = $orderCurrency;
					$rowActionDescription['LIMIT_VALUE'] = $limitValue;
				}

				//сохранение примененной скидки в объекте результата
				static::setActionResult(self::RESULT_ENTITY_BASKET, $rowActionDescription);
				unset($rowActionDescription);
			}
			unset($result);
		}
		unset($basketCode, $basketRow);
	}


	/**
	 * Возвращает перечень товаров, подходящих под фильтр ПРСК
	 * @param array $order Массив заказа
	 * @param array $action Правило Работы с Корзиной
	 * @param $filter
	 * @return array|mixed Отфильтрованные товары
	 */
	public static function getFilteredBasket(array $order, array $action, $filter)
	{
		$result = is_array($order['BASKET_ITEMS']) ? $order['BASKET_ITEMS'] : [];

		static::enableBasketFilter();
		$result = static::getBasketForApply($result, $filter, $action);
		if (!empty($result)) {
			$result = array_filter($result, '\Bitrix\Sale\Discount\Actions::filterBasketForAction');
		}

		return $result;
	}


	/**
	 * Функция для доступа к protected методу для использования в Discount
	 * @param float|int $value
	 * @param string $unit
	 * @param array $basketRow
	 * @param float|int|null $limitValue
	 * @param bool $maxBound
	 * @return array
	 */
	public static function calculateDiscountPrice($value, $unit, array $basketRow, $limitValue, $maxBound)
	{
		return parent::calculateDiscountPrice($value, $unit, $basketRow, $limitValue, $maxBound);
	}
}