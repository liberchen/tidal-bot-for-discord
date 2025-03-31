<?php
namespace App;

use GuzzleHttp\Client;

class TideService
{
    private Client $client;
    private string $apiToken;
    private string $resourceId = 'F-A0021-001';

    public function __construct(string $apiToken)
    {
        $this->apiToken = $apiToken;
        $this->client = new Client([
            'base_uri' => 'https://opendata.cwa.gov.tw/api/v1/rest/datastore/',
            'timeout'  => 10.0,
        ]);
    }

    /**
     * 取得指定地點與日期的潮汐預報資料
     *
     * @param string $locationId 地點代碼
     * @param string $date       日期 (格式: yyyy-mm-dd)
     * @return array|null 返回該日預報中 Time 陣列資料，若無資料則傳回 null
     */
    public function getTideForecast(string $locationId, string $date): ?array
    {
        $endpoint = $this->resourceId;
        $params = [
            'query' => [
                'Authorization' => $this->apiToken,
                'format'        => 'JSON',
                'LocationId'    => $locationId,
                'Date'          => $date,
            ]
        ];

        try {
            $response = $this->client->get($endpoint, $params);
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                if (isset($data['success']) && $data['success'] === "true") {
                    $tideForecasts = $data['records']['TideForecasts'] ?? null;
                    if ($tideForecasts && is_array($tideForecasts)) {
                        // 取第一筆預報資料
                        $forecast = $tideForecasts[0] ?? null;
                        if ($forecast && isset($forecast['Location']['TimePeriods']['Daily'])) {
                            $dailyForecasts = $forecast['Location']['TimePeriods']['Daily'];
                            // 尋找符合指定日期的預報
                            foreach ($dailyForecasts as $daily) {
                                if (isset($daily['Date']) && $daily['Date'] === $date) {
                                    return $daily['Time'] ?? null;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("潮汐 API 請求錯誤: " . $e->getMessage());
        }
        return null;
    }
}
