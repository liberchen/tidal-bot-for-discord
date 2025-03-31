<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Discord\Discord;
use Discord\Builders\CommandBuilder;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\SelectMenu;
use Discord\Parts\Interactions\Interaction;
use Discord\WebSockets\InteractionResponseTypes;
use App\TideService;
use App\LocationHelper;
use Dotenv\Dotenv;

// 載入 .env 檔案
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// 設定時區為台北
date_default_timezone_set('Asia/Taipei');

// 取得環境變數
$discordToken = $_ENV['DISCORD_TOKEN'] ?? null;
$tideApiToken = $_ENV['TIDE_API_TOKEN'] ?? null;

if (!$discordToken || !$tideApiToken) {
    echo "環境變數設定錯誤，請確認 DISCORD_TOKEN 與 TIDE_API_TOKEN 已正確設定。" . PHP_EOL;
    exit(1);
}

$tideService = new TideService($tideApiToken);
$locationHelper = new LocationHelper(__DIR__ . '/../data/locations.json');

// 建立 Discord Bot 實例
$discord = new Discord([
    'token'   => $discordToken,
    'intents' => Discord::INTENTS_ALL,
]);

$discord->on('ready', function (Discord $discord) use ($tideService, $locationHelper) {
    echo "Bot 已啟動且準備就緒！" . PHP_EOL;

    // 註冊 Slash Command: 潮汐查詢
    $commandName = '潮汐查詢';
    $commandDescription = '選擇一個地點查看今日潮汐預報';

    $command = new CommandBuilder();
    $command->setName($commandName)
        ->setDescription($commandDescription);

    // 取得現有指令，避免重複註冊
    $discord->application->commands->freshen()->done(function ($commands) use ($discord, $command, $commandName) {
        $exists = false;
        foreach ($commands as $cmd) {
            if ($cmd->name === $commandName) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $discord->application->commands->save($command);
            echo "已註冊指令: {$commandName}" . PHP_EOL;
        } else {
            echo "指令 {$commandName} 已存在，略過註冊。" . PHP_EOL;
        }
    });

    // 監聽互動事件
    $discord->on('interactionCreate', function (Interaction $interaction) use ($tideService, $locationHelper) {
        // 處理 Slash Command 互動
        if (isset($interaction->data->name) && $interaction->data->name === '潮汐查詢') {
            // 取得所有地點資料
            $locations = $locationHelper->search('');
            $options = [];
            foreach ($locations as $id => $name) {
                $options[] = [
                    'label' => $name,
                    'value' => $id,
                ];
            }

            $selectMenu = SelectMenu::new('tide_location')
                ->setPlaceholder('請選擇地點')
                ->addOptions($options);

            $actionRow = ActionRow::new()->addComponent($selectMenu);

            $interaction->respondWithMessage(InteractionResponseTypes::CHANNEL_MESSAGE_WITH_SOURCE, [
                'content'    => '請選擇地點：',
                'components' => [$actionRow],
                'ephemeral'  => true
            ]);
        }

        // 處理下拉選單選擇結果
        if (isset($interaction->data->component_type) && $interaction->data->component_type === 3 &&
            isset($interaction->data->custom_id) && $interaction->data->custom_id === 'tide_location') {

            $locationId = $interaction->data->values[0];
            $locationName = $locationHelper->getNameById($locationId);
            $today = date('Y-m-d'); // 確保格式為 yyyy-mm-dd
            $tides = $tideService->getTideForecast($locationId, $today);

            if ($tides && is_array($tides)) {
                $reply = "📍 {$locationName} 今日潮汐預報：\n";
                foreach ($tides as $tide) {
                    // 檢查必要資料是否存在
                    if (isset($tide['DateTime'], $tide['Tide'], $tide['TideHeights']['AboveChartDatum'])) {
                        $reply .= sprintf(
                            "%s - %s（潮高：%dcm）\n",
                            $tide['DateTime'],
                            $tide['Tide'],
                            $tide['TideHeights']['AboveChartDatum']
                        );
                    }
                }
                $interaction->respondWithMessage(InteractionResponseTypes::CHANNEL_MESSAGE_WITH_SOURCE, [
                    'content' => $reply
                ]);
            } else {
                $interaction->respondWithMessage(InteractionResponseTypes::CHANNEL_MESSAGE_WITH_SOURCE, [
                    'content' => "⚠️ 找不到潮汐資料，請稍後再試。"
                ]);
            }
        }
    });
});

$discord->run();
