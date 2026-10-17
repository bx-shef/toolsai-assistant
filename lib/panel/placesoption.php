<?php declare(strict_types=1);

namespace Shef\ToolsAiAssistant\Panel;

use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Shef\Options\Main\Options\AOption;
use Shef\ToolsAiAssistant\Config;
use Shef\ToolsAiAssistant\Internals\Integration\Ui\EntitySelector\PlaceProvider;

/**
 * Поле «Где показывать чат» на странице настроек (1.3.0): подбор мест через
 * ui.entity-selector (BX.UI.EntitySelector.TagSelector, провайдер
 * PlaceProvider) плюс свой адрес — набрать его в поиске и нажать «Добавить».
 *
 * Хранит то же, что textarea до 1.3.0: адреса по одному в строке (скрытое
 * поле param_PANEL_paths, его сохраняет options.php shef.options). Поэтому
 * старые настройки читаются как есть, Config не менялся по формату.
 *
 * Класс опции shef.options (AOption::renderValue()); грузится только на
 * странице настроек, где shef.options уже подключён.
 */
final class PlacesOption extends AOption
{
	protected function renderValue(string $moduleId): string
	{
		Extension::load(['ui.entity-selector']);

		// Сохранённое — через тот же разбор, что и у Config: мусор не показываем
		$saved = Config::fromValues([Config::OPTION_PATHS => htmlspecialcharsback($this->getInputValue($moduleId))])->paths;
		$preselected = array_map(static fn(string $url): array => [PlaceProvider::ENTITY_ID, $url], $saved);
		$containerId = 'shef-assistant-places-'.$this->getCode();

		$conf = [
			'inputId' => htmlspecialcharsback($this->getInputId()),
			'containerId' => $containerId,
			'entityId' => PlaceProvider::ENTITY_ID,
			'preselected' => $preselected,
			'pattern' => '^/(?!/)[\\w\\-./%~]*(\\?[\\w\\-.%=&\\[\\]~+]*)?$',
			'createLabel' => 'Добавить свой адрес',
			'customSubtitle' => 'Свой адрес',
			'badUrl' => 'Адрес — путь своего портала, например /crm/deal/ или /shef/toolsai/view/?type=2',
		];

		ob_start();
		?>
<input type="hidden" name="<?= $this->getInputName() ?>" id="<?= $this->getInputId() ?>" value="<?= htmlspecialcharsbx(implode("\n", $saved)) ?>">
<div id="<?= htmlspecialcharsbx($containerId) ?>"></div>
<script>
BX.ready(function() {
	var conf = <?= Json::encode($conf) ?>;
	var input = document.getElementById(conf.inputId);
	var pattern = new RegExp(conf.pattern, 'u');
	var sync = function(selector) {
		input.value = selector.getTags().map(function(tag) { return String(tag.getId()); }).join("\n");
	};
	var selector = new BX.UI.EntitySelector.TagSelector({
		multiple: true,
		showAvatars: false,
		dialogOptions: {
			context: 'SHEF_ASSISTANT_PLACES',
			entities: [{ id: conf.entityId, dynamicLoad: true, dynamicSearch: false }],
			preselectedItems: conf.preselected,
			showAvatars: false,
			compactView: true,
			searchOptions: { allowCreateItem: true, footerOptions: { label: conf.createLabel } },
			events: {
				'Search:onItemCreateAsync': function(event) {
					var dialog = event.getTarget();
					var url = String(event.getData().searchQuery.getQuery() || '').trim().replace(/[?&]+$/, '');
					return new Promise(function(resolve, reject) {
						if(!pattern.test(url))
						{
							alert(conf.badUrl);
							reject();
							return;
						}
						var item = dialog.getItem([conf.entityId, url]) || dialog.addItem({
							id: url, entityId: conf.entityId, entityType: 'custom',
							title: url, subtitle: conf.customSubtitle, tabs: 'recents'
						});
						item.select();
						resolve();
					});
				}
			}
		},
		events: {
			onAfterTagAdd: function(event) { sync(event.getTarget()); },
			onAfterTagRemove: function(event) { sync(event.getTarget()); }
		}
	});
	selector.renderTo(document.getElementById(conf.containerId));
});
</script>
		<?php

		return (string)ob_get_clean();
	}
}
