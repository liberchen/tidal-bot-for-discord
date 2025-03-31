<?php
namespace App;

class LocationHelper
{
    private array $locations;

    /**
     * 從 JSON 檔案讀取地點資料
     *
     * @param string $jsonFile JSON 檔案路徑
     */
    public function __construct(string $jsonFile)
    {
        if (file_exists($jsonFile)) {
            $contents = file_get_contents($jsonFile);
            $this->locations = json_decode($contents, true);
        } else {
            $this->locations = [];
        }
    }

    /**
     * 搜尋地點。若 query 為空字串，則回傳所有地點。
     *
     * @param string $query 搜尋字串
     * @return array 形式為 [id => name, ...]
     */
    public function search(string $query): array
    {
        if ($query === '') {
            return $this->locations;
        }
        $result = [];
        foreach ($this->locations as $id => $name) {
            if (stripos($name, $query) !== false) {
                $result[$id] = $name;
            }
        }
        return $result;
    }

    /**
     * 根據地點 ID 取得對應名稱
     *
     * @param string $id
     * @return string|null
     */
    public function getNameById(string $id): ?string
    {
        return $this->locations[$id] ?? null;
    }
}
