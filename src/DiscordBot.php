<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Discord\Discord;
use Discord\WebSockets\Intents;
use Discord\Builders\CommandBuilder;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\SelectMenu;
use Discord\Parts\Interactions\Interaction;
use App\TideService;
use App\LocationHelper;
use Dotenv\Dotenv;

// 載入 .env 檔案（Heroku 上會使用 Config Vars）
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

// 建立 Discord Bot 實例，使用 GUILDS intents
$discord = new Discord([
    'token'   => $discordToken,
    'intents' => Intents::GUILDS,
]);

$discord->on('ready', function (Discord $discord) use ($tideService, $locationHelper) {
    echo "Bot is ready." . PHP_EOL;

    // 註冊 Slash Command，名稱與描述皆為英文
    $commandName = 'tide';
    $commandDescription = "Select a location to check today's tide forecast";
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
            echo "Registered command: {$commandName}" . PHP_EOL;
        } else {
            echo "Command {$commandName} already exists. Skipping registration." . PHP_EOL;
        }
    });

    // 監聽互動事件
    $discord->on('interactionCreate', function (Interaction $interaction) use ($tideService, $locationHelper) {
        // 處理 Slash Command 互動（英文指令）
        if (isset($interaction->data->name) && $interaction->data->name === 'tide') {
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
                ->setPlaceholder('Please select a location')
                ->addOptions($options);

            $actionRow = ActionRow::new()->addComponent($selectMenu);

            // 使用數值 4 代表 CHANNEL_MESSAGE_WITH_SOURCE
            $interaction->respondWithMessage(4, [
                'content'    => 'Please select a location:',
                'components' => [$actionRow],
                'ephemeral'  => true
            ]);
        }

        // 處理下拉選單選擇結果
        if (isset($interaction->data->component_type) && $interaction->data->component_type === 3 &&
            isset($interaction->data->custom_id) && $interaction->data->custom_id === 'tide_location') {

            $locationId = $interaction->data->values[0];
            $locationName = $locationHelper->getNameById($locationId);
            $today = date('Y-m-d'); // 日期格式：yyyy-mm-dd
            $tides = $tideService->getTideForecast($locationId, $today);

            if ($tides && is_array($tides)) {
                $reply = "📍 Tide forecast for {$locationName} on {$today}:\n";
                foreach ($tides as $tide) {
                    if (isset($tide['DateTime'], $tide['Tide'], $tide['TideHeights']['AboveChartDatum'])) {
                        $time = date("H:i", strtotime($tide['DateTime']));
                        $reply .= sprintf("%s - %s (Height: %dcm)\n", $time, $tide['Tide'], $tide['TideHeights']['AboveChartDatum']);
                    }
                }
                $interaction->respondWithMessage(4, [
                    'content' => $reply
                ]);
            } else {
                $interaction->respondWithMessage(4, [
                    'content' => "⚠️ Unable to retrieve tide forecast. Please try again later."
                ]);
            }
        }
    });
});

$discord->run();
