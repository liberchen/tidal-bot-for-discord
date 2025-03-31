# tidal-bot-for-discord 🌊

使用 PHP 開發的 Discord bot，結合氣象局開放資料平台，提供即時潮汐預報。

## 指令
- `!潮汐 貢寮`：顯示今天對應地區的潮汐預報，支援模糊搜尋

## 本地環境
建立 `.env` 並加入：

```
DISCORD_TOKEN=你的 Discord Bot Token
TIDE_API_TOKEN=你的氣象 API Token
```

## Heroku 部署指令

```bash
heroku login
heroku git:remote -a tidal-bot-for-discord
heroku config:set DISCORD_TOKEN=你的token
heroku config:set TIDE_API_TOKEN=你的token
heroku ps:scale worker=1
```

## 執行 bot
```bash
php src/DiscordBot.php
```

## 地區搜尋
所有地點 ID 與名稱對應表請見 `data/locations.json`

---

開源於 GitHub: [tidal-bot-for-discord](https://github.com/liberchen/tidal-bot-for-discord)
