<?php

namespace R3ct4lan\DiscountComparison\Providers;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityError;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\LanguageTable;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Result;
use CAdminNotify;
use CSaleActionCtrlBasketGroup;
use ParseError;


/**
 * Class Discount
 * Расширяет класс Bitrix\Crm\Order\Discount, который отвечает за применение правил работы с корзиной.
 * @package R3ct4lan\DiscountComparison
 * @author Maxim Smolkov
 */
class Discount extends \Bitrix\Crm\Order\Discount
{
	/**
	 * Порядок применения скидок
	 * @return Result
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function executeDiscountList()
	{
		$result = new Result; //пустой объект результата

		$roundApply = true; //округление уже применено
		$saleDiscountOnly = $this->useOnlySaleDiscounts(); //настройка "Использовать только правила корзины" в модуле Интернет-магазин
		$useMode = $this->getUseMode(); //способ расчета скидок:
		// USE_MODE_APPLY - Расчет скидки по существующему заказу
		// USE_MODE_MIXED - Расчет скидки по существующему заказу с новыми товарами
		// USE_MODE_FULL - Расчет скидки по новому заказу

		if ($saleDiscountOnly) {
			if ($useMode == self::USE_MODE_FULL && $this->isRoundMode(self::ROUND_MODE_SALE_DISCOUNT))
				$roundApply = false; //округление цен ещё не применялось
		}

		$this->discountIds = array(); //список id всех активных ПРСК
		if (empty($this->saleDiscountCacheKey) || empty($this->saleDiscountCache[$this->saleDiscountCacheKey])) { //в кеше нет ПРСК
			if (!$roundApply) {
				//округлить цену и выйти
				$this->roundFullBasketPriceByIndex(array(
					'DISCOUNT_INDEX' => -1,
					'DISCOUNT_ID' => 0
				));
			}
			return $result;
		}

		$currentList = $this->saleDiscountCache[$this->saleDiscountCacheKey]; //список всех активных ПРСК из кеша
		$this->discountIds = array_keys($currentList);
		$this->extendOrderData(); //отправка события onExtendOrderData

		Actions::clearAction(); //сбрасывает счетчики примененных скидок

		$blackList = array( //ХЗ
			self::getExecuteFieldName('UNPACK') => true, //EXECUTE_UNPACK
			self::getExecuteFieldName('APPLICATION') => true, //EXECUTE_APPLICATION
			self::getExecuteFieldName('PREDICTIONS_APP') => true //EXECUTE_PREDICTIONS_APP
		);

		// Выполнение скидок по отдельности на каждый товар
		$this->executeDiscountsOnItems($currentList);
		// Из массива $currentList удаляются скидки, которые могли в последствии менять цену товаров

		$index = -1; //счетчик примененных скидок
		$skipPriorityLevel = null; //флаг "Прекратить применение скидок на текущем уровне приоритетов"
		//перебор всех скидок (по порядку приоритета применимости)
		foreach ($currentList as $discountIndex => $discount) {
			if ($skipPriorityLevel == $discount['PRIORITY']) {
				continue; //пропуск на текущем уровне приоритета
			}
			$skipPriorityLevel = null;

			$this->fillCurrentStep(array( //запись свойства currentStep, которое далее испольуется при расчетах
				'discount' => $discount,
				'cacheIndex' => $discountIndex
			));
			if (!$this->checkDiscountConditions()) //проверка условий применения (общих, не касающихся торавров в отдельности)
				continue;

			$index++;
			if (!$roundApply && $discount['EXECUTE_MODULE'] == 'sale') {
				//округление цен в корзине
				$this->roundFullBasketPriceByIndex(array(
					'DISCOUNT_INDEX' => $index,
					'DISCOUNT_ID' => $discount['ID']
				));
				$roundApply = true;
			}

			//ПРСК, которое прошло проверку условий, записывается в свойство fullDiscountList
			if ($useMode == self::USE_MODE_FULL && !isset($this->fullDiscountList[$discount['ID']]))
				$this->fullDiscountList[$discount['ID']] = array_diff_key($discount, $blackList);

			//применение ПРСК на корзину
			$actionsResult = $this->applySaleDiscount();

			if (!$actionsResult->isSuccess()) { //если фатал
				$result->addErrors($actionsResult->getErrors());
				unset($actionsResult);
				return $result;
			}

			if ($this->currentStep['stop']) //если стоит галочка "Прекратить дальнейшее применение правил"
				break;

			if ($this->currentStep['stopLevel']) { //если стоит галочка "Прекратить применение скидок на текущем уровне приоритетов"
				$skipPriorityLevel = $discount['PRIORITY']; //запись пропускаемого уровня
			}
		}
		unset($discount, $currentList);
		$this->fillEmptyCurrentStep(); //обнуление свойства currentStep

		if (!$roundApply) { //если корзина так и не округлялась
			$index++;
			$this->roundFullBasketPriceByIndex(array( //округление цен
				'DISCOUNT_INDEX' => $index,
				'DISCOUNT_ID' => 0
			));
		}

		return $result;
	}


	/**
	 * Отображение ошибки
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function showAdminError()
	{
		$iterator = CAdminNotify::GetList(
			array(),
			array('MODULE_ID' => 'sale', 'TAG' => self::ERROR_ID)
		);
		$notify = $iterator->Fetch();
		unset($iterator);
		if (empty($notify)) {
			$defaultLang = '';
			$messages = array();
			$languages = LanguageTable::getList(array(
				'select' => array('ID', 'DEF'),
				'filter' => array('=ACTIVE' => 'Y')
			));
			while ($row = $languages->fetch()) {
				if ($row['DEF'] == 'Y')
					$defaultLang = $row['ID'];
				$languageId = $row['ID'];
				Loc::loadLanguageFile(
					__FILE__,
					$languageId
				);
				$messages[$languageId] = Loc::getMessage(
					'BX_SALE_DISCOUNT_ERR_PARSE_ERROR',
					array('#LINK#' => '/bitrix/admin/settings.php?lang=' . $languageId . '&mid=sale'),
					$languageId
				);
			}
			unset($row, $languages);

			CAdminNotify::Add(array(
				'MODULE_ID' => 'sale',
				'TAG' => self::ERROR_ID,
				'ENABLE_CLOSE' => 'N',
				'NOTIFY_TYPE' => CAdminNotify::TYPE_ERROR,
				'MESSAGE' => $messages[$defaultLang],
				'LANG' => $messages
			));
			unset($messages, $defaultLang);
		}
		unset($notify);
	}


	/**
	 * Применение скидок на каждый товар в отдельности
	 * @param array $discountsList
	 * @return Result
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function executeDiscountsOnItems(&$discountsList)
	{
		$result = new Result; //пустой объект результата
		$usedIndexes = array(); //массив id скидок. в него войдут те скидки,
		// которые могут примениться на товар и уменьшить его стоимость

		Actions::clearAction();  //сбрасывает счетчики примененных скидок

		//перебор всех товаров в корзине
		foreach ($this->orderData['BASKET_ITEMS'] as $basketItem) {

			//получаем массив скидок, сортированных по величине скидки (в деньгах) для конкретного товара
			$itemDiscounts = $this->getItemDiscountsList($discountsList, $basketItem);


			$discountKeys = array_keys($itemDiscounts); //массив id скидок для товара
			$usedIndexes = array_merge($discountKeys, $usedIndexes); //добавляем в общий массив применяемых скидок

			//для применения к товару выбираем первую (т.е. самую выгодную скидку) из найденных
			$discountIndex = $discountKeys[0];
			$discount = array_values($itemDiscounts)[0];

			//запись свойства currentStep, которое далее испольуется при расчетах
			$this->fillCurrentStep(['cacheIndex' => $discountIndex, 'discount' => $discount]);

			//применение ПРСК только на конкретный товар
			$actionsResult = $this->applySaleDiscountOnItem($basketItem);

			if (!$actionsResult->isSuccess()) { //если фатал
				$result->addErrors($actionsResult->getErrors());
				return $result;
			}
		}
		unset($discount);
		$this->fillEmptyCurrentStep(); //обнуление свойства currentStep


		//удаляем из списка те скидки, которые изменяли стоимость товаров,
		// чтобы далее, в основном методе, они не применились повторно
		foreach ($usedIndexes as $usedIndex) {
			unset($discountsList[$usedIndex]);
		}

		return $result;
	}


	/**
	 * Применение скидки на товар
	 * @param $basketItem
	 * @return Result
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @see \Bitrix\Sale\DiscountBase::applySaleDiscount
	 */
	protected function applySaleDiscountOnItem($basketItem)
	{
		$result = new Result;

		Actions::clearApplyCounter();

		//получение применяемой скидки из свойства или из кеша
		$discount = isset($this->currentStep['discountIndex'])
			? $this->discountsCache[$this->currentStep['discountId']]
			: $this->currentStep['discount'];

		if (isset($this->currentStep['discountIndex'])) {
			//если ПРСК требует подгрузки сторонних модулей, а модули не загружаются
			if (!empty($discount['APPLICATION']) && !$this->loadDiscountModules($this->discountsCache[$this->currentStep['discountId']]['MODULES'])) {
				$discount['APPLICATION'] = null;
				$result->addError(new EntityError( //"Невозможно пересчитать правило, использованное в заказе - отсутствуют требуемые модули"
					Loc::getMessage('BX_SALE_DISCOUNT_ERR_SALE_DISCOUNT_MODULES_ABSENT'),
					self::ERROR_ID
				));
			}
		}

		if (!empty($discount['APPLICATION'])) { //задана функция расчета скидки

			$this->rewriteCustomApplication($discount); //Переопределяем провайдер расчета скидки

			$executeKey = self::getExecuteFieldName('APPLICATION'); //EXECUTE_APPLICATION

			//кусок далее в оригинальном коде выполнялся только, если EXECUTE_APPLICATION не было определено
			//но мы принудительно переопределяем функцию расчета на свою

			$discount[$executeKey] = null;
			$evalCode = '$discount["' . $executeKey . '"] = ' . $discount['APPLICATION'] . ';';
			if (PHP_MAJOR_VERSION >= 7) {
				try {
					eval($evalCode);
				} catch (ParseError $e) {
					$this->showAdminError();
				}
			} else {
				eval($evalCode);
			}
			unset($evalCode);

			if (is_callable($discount[$executeKey])) { //EXECUTE_APPLICATION - вызываемая функция

				$currentUseMode = $this->getUseMode(); //способ расчета скидок
				$this->currentStep['oldData'] = $this->orderData; //перед применением скидки состояние корзины заказа сохраняется

				if ($currentUseMode == self::USE_MODE_APPLY || $currentUseMode == self::USE_MODE_MIXED) {
					//получаем дополнительные данные, используемые для расчета скидок для существующего заказа
					$discountStoredActionData = $this->getDiscountStoredActionData($this->currentStep['discountId']);
					if (!empty($discountStoredActionData) && is_array($discountStoredActionData))
						Actions::setStoredData($discountStoredActionData); //доп. данные применяются
					unset($discountStoredActionData);
				}

				//здесь скидка применяется на один товар корзины
				// @see R3ct4lan\DiscountComparison\Providers\Actions::applyToBasketItem
				$discount[$executeKey]($this->orderData, $basketItem);

				//пересчет корзины после применения ПРСК
				switch ($currentUseMode) { //в зависимости от способа расчета
					case self::USE_MODE_COUPONS:
					case self::USE_MODE_FULL:
						$actionsResult = $this->calculateFullSaleDiscountResult();
						break;
					case self::USE_MODE_APPLY:
					case self::USE_MODE_MIXED:
						$actionsResult = $this->calculateApplySaleDiscountResult();
						break;
					default:
						$actionsResult = new Result;
				}

				if (!$actionsResult->isSuccess()) //фатал
					$result->addErrors($actionsResult->getErrors());

				unset($actionsResult);
				unset($currentUseMode);
			}
		}
		unset($discount);
		Actions::clearAction();

		return $result;

	}


	/**
	 * Получает список применяемых правил, отсортированных по стоимости скидки на конкретный товар
	 * @param array $discountsList
	 * @param array $basketItem
	 * @return array
	 */
	protected function getItemDiscountsList(array $discountsList, array $basketItem)
	{
		$result = array(); //массив скидок для товара
		$useMode = $this->getUseMode(); //способ расчета скидок

		$blackList = array( //ХЗ
			self::getExecuteFieldName('UNPACK') => true, //EXECUTE_UNPACK
			self::getExecuteFieldName('APPLICATION') => true, //EXECUTE_APPLICATION
			self::getExecuteFieldName('PREDICTIONS_APP') => true //EXECUTE_PREDICTIONS_APP
		);

		foreach ($discountsList as $discountIndex => $discount) {
			//запись свойства currentStep, которое далее испольуется при расчетах
			$this->fillCurrentStep(array('discount' => $discount, 'cacheIndex' => $discountIndex));

			//проверка условий применения
			if (!$this->checkDiscountConditions())
				continue;

			//записываем скидку в полный скидок всех прошедших отбор скидок
			if ($useMode == self::USE_MODE_FULL && !isset($this->fullDiscountList[$discount['ID']])) {
				$this->fullDiscountList[$discount['ID']] = array_diff_key($discount, $blackList);
			}

			//соответствует ли товар условию скидки
			if($this->checkDiscountFilter($discount, $basketItem)) {

				//получаем величину применяемой скидки на конкретный товар
				$discountCost = $this->calculateDiscountPrice($discount, $basketItem);

				if ($discountCost < 0) //если скидка уменьшает цену товара
					$result[$discountIndex] = $discountCost; //добавляем в список
			}
		}
		//сортируем список отобранных скидок по возрастанию (по наибольшей вычитаемой стоимости товара)
		asort($result);

		//формируем список скидок на вывод
		foreach ($result as $discountIndex => $discountPrice) {
			$result[$discountIndex] = $discountsList[$discountIndex];
		}

		return $result;
	}


	/**
	 * Проверяет, проходит ли товар по фильтру ПРСК
	 * @param array $discount
	 * @param array $basketItem
	 * @return bool
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function checkDiscountFilter($discount, array $basketItem)
	{
		$result = false;

		//устанавливаем функцию-фильтр ПРСК
		$filter = $this->getDiscountFilter($discount);
		$setFilter = '$filter = ' . $filter . ';';
		if (PHP_MAJOR_VERSION >= 7) {
			try {
				eval($setFilter);
			} catch (ParseError $e) {
				$this->showAdminError();
			}
		} else {
			eval($setFilter);
		}
		unset($setFilter);

		//получаем отфильтрованную корзину
		$filteredBasket = Actions::getFilteredBasket($this->orderData, $discount, $filter);

		foreach ($filteredBasket as $item) { //проверяем проходит ли товар фильтр
			if($basketItem['ID'] == $item['ID']) {
				$result = true;
				break;
			}
		}

		return $result;
	}

	/**
	 * Считает размер скидки на товар
	 * @param $discount
	 * @param array $basketRow
	 * @return float|int|false
	 * @see \Bitrix\Sale\Discount\Actions::calculateDiscountPrice
	 */
	protected function calculateDiscountPrice($discount, array $basketRow)
	{
		$structureDiscount = $discount['SHORT_DESCRIPTION_STRUCTURE']; //получаем описание действия ПРСК
		$koff = $structureDiscount['TYPE'] == CSaleActionCtrlBasketGroup::ACTION_TYPE_DISCOUNT ? -1 : 1; //скидка или наценка
		$discountDiff = floatval($structureDiscount['VALUE']) * $koff; //применяемое значение (денег или процентов)
		$unit = $structureDiscount['VALUE_TYPE']; //скидка в деньгах или в процентах
		$limitValue = $structureDiscount['LIMIT_VALUE']; //максимальная сумма скидки ("применить ... не более [limitValue]")
		$maxBound = false; //Разрешить устанавливать цену в 0, если скидка больше цены
		if ($unit == Actions::VALUE_TYPE_FIX && $discountDiff < 0)
			$maxBound = (isset($structureDiscount['MAX_BOUND']) && $structureDiscount['MAX_BOUND'] == 'Y');

		//расчет цены скидки на конкретный товар
		list($discountDiff, $result) = Actions::calculateDiscountPrice($discountDiff, $unit, $basketRow, $limitValue, $maxBound);

		return $discountDiff;
	}


	/**
	 * Получение функции-фильтра ПРСК
	 * @param $discount
	 * @return string
	 */
	protected function getDiscountFilter($discount)
	{
		$result = 'function($row){return true;}'; //фильтр по умалчанию пропускает все товары
		$matches = array();

		//вытаскиваем строковую запись функции из ПРСК
		preg_match('/=function\(\$row\){.*};/', $discount['APPLICATION'], $matches);
		if(!empty($matches)) {
			$function = array_values($matches)[0];
			$result = substr($function, 1, -1);
		}

		return $result;
	}


	/**
	 * Переопределение выполняемой функции правила работы с корзиной
	 * @param array $discount
	 */
	protected function rewriteCustomApplication(&$discount)
	{
		//вместо расчета applyToBasket на всю корзину будет выполняться applyToBasketItem на один товар из корзины
		$discount['APPLICATION'] = str_replace(
			'function (&$arOrder){',
			'function (&$arOrder, $basketItem){',
			$discount['APPLICATION']
		);
		$discount['APPLICATION'] = str_replace(
			'\\Bitrix\\Sale\\Discount\\Actions::applyToBasket($arOrder, ',
			'\\R3ct4lan\\DiscountComparison\\Providers\\Actions::applyToBasketItem($arOrder, $basketItem, ',
			$discount['APPLICATION']
		);
	}


}