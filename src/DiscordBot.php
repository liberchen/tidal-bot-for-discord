<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Discord\Discord;
use Discord\WebSockets\Intents;
use Discord\Builders\CommandBuilder;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\SelectMenu;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Interactions\Command\Command as DiscordCommand; // 正確命名空間
use App\TideService;
use App\LocationHelper;
use Dotenv\Dotenv;

// 簡單的 debug log 函式
function debug_log($message) {
    echo date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
}

// 載入 .env 檔案（Heroku 上使用 Config Vars，不一定有 .env）
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();
debug_log("Environment variables loaded (using safeLoad).");

// 設定時區為台北
date_default_timezone_set('Asia/Taipei');
debug_log("Timezone set to Asia/Taipei.");

// 取得環境變數
$discordToken = $_ENV['DISCORD_TOKEN'] ?? null;
$tideApiToken = $_ENV['TIDE_API_TOKEN'] ?? null;
$guildId = $_ENV['GUILD_ID'] ?? null; // 選擇性用於測試用的 guild 指令

if (!$discordToken || !$tideApiToken) {
    debug_log("Missing environment variables. Exiting.");
    exit(1);
}

$tideService = new TideService($tideApiToken);
$locationHelper = new LocationHelper(__DIR__ . '/../data/locations.json');
debug_log("TideService and LocationHelper instantiated.");

// 建立 Discord Bot 實例，僅訂閱 GUILDS 事件（支援 slash 指令）
$discord = new Discord([
    'token'   => $discordToken,
    'intents' => Intents::GUILDS,
]);

// 使用 'init' 事件（取代已廢棄的 'ready'）
$discord->on('init', function (Discord $discord) use ($tideService, $locationHelper, $guildId) {
    debug_log("Bot is initialized and connected to Discord.");

    // 指令定義
    $commandName = 'tide';
    $commandDescription = "Select a location to check today's tide forecast";
    $builder = new CommandBuilder();
    $builder->setName($commandName)
        ->setDescription($commandDescription);

    // 轉換指令資料並建立 DiscordCommand 物件
    $payload = $builder->toArray();
    $discordCommand = new DiscordCommand($discord);
    $discordCommand->fill($payload);

    // 註冊指令：如果有設定 GUILD_ID，則註冊為 guild 指令；否則註冊全域指令
    if ($guildId) {
        debug_log("Registering guild command for Guild ID: {$guildId}");
        // 傳入查詢參數陣列 ['guild_id' => $guildId]
        $discord->application->commands->freshen(['guild_id' => $guildId])->then(
            function ($commands) use ($discord, $discordCommand, $commandName, $guildId) {
                $exists = false;
                foreach ($commands as $cmd) {
                    if ($cmd->name === $commandName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $discord->application->commands->save($discordCommand, ['guild_id' => $guildId])->then(
                        function () use ($commandName, $guildId) {
                            debug_log("Guild command '{$commandName}' registered successfully for Guild ID: {$guildId}");
                        },
                        function ($e) {
                            debug_log("Error registering guild command: " . $e->getMessage());
                        }
                    );
                } else {
                    debug_log("Guild command '{$commandName}' already exists. Skipping registration.");
                }
            },
            function ($e) {
                debug_log("Error fetching guild commands: " . $e->getMessage());
            }
        );
    } else {
        debug_log("Registering global command.");
        $discord->application->commands->freshen()->then(
            function ($commands) use ($discord, $discordCommand, $commandName) {
                $exists = false;
                foreach ($commands as $cmd) {
                    if ($cmd->name === $commandName) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $discord->application->commands->save($discordCommand)->then(
                        function () use ($commandName) {
                            debug_log("Global command '{$commandName}' registered successfully.");
                        },
                        function ($e) {
                            debug_log("Error registering global command: " . $e->getMessage());
                        }
                    );
                } else {
                    debug_log("Global command '{$commandName}' already exists. Skipping registration.");
                }
            },
            function ($e) {
                debug_log("Error fetching global commands: " . $e->getMessage());
            }
        );
    }

    // 監聽互動事件
    $discord->on('interactionCreate', function (Interaction $interaction) use ($tideService, $locationHelper) {
        debug_log("Received an interaction event.");

        // 處理 Slash Command (英文指令)
        if (isset($interaction->data->name) && $interaction->data->name === 'tide') {
            debug_log("Processing '/tide' command interaction.");
            $locations = $locationHelper->search('');
            $options = [];
            foreach ($locations as $id => $name) {
                $options[] = [
                    'label' => $name,
                    'value' => $id,
                ];
            }
            debug_log("Prepared location options: " . json_encode($options));

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
            debug_log("Responded to '/tide' command with location select menu.");
        }

        // 處理下拉選單選擇結果
        if (isset($interaction->data->component_type) && $interaction->data->component_type === 3 &&
            isset($interaction->data->custom_id) && $interaction->data->custom_id === 'tide_location') {
            debug_log("Processing selection from 'tide_location' menu.");
            $locationId = $interaction->data->values[0];
            $locationName = $locationHelper->getNameById($locationId);
            $today = date('Y-m-d'); // 日期格式：yyyy-mm-dd
            $tides = $tideService->getTideForecast($locationId, $today);
            debug_log("Fetched tide forecast for location ID {$locationId} on {$today}.");

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
                debug_log("Responded with tide forecast: " . $reply);
            } else {
                $interaction->respondWithMessage(4, [
                    'content' => "⚠️ Unable to retrieve tide forecast. Please try again later."
                ]);
                debug_log("Failed to fetch tide forecast; sent error response.");
            }
        }
    });
});

debug_log("Starting Discord Bot...");
$discord->run();
