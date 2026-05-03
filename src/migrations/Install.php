<?php
namespace wsydney76\priceadjuster\migrations;
use craft\db\Migration;
use wsydney76\priceadjuster\records\PriceSchedule;
class Install extends Migration
{
    public function safeUp(): bool
    {
        $table = PriceSchedule::tableName();
        if ($this->db->tableExists($table)) {
            return true;
        }
        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'effectiveDate' => $this->date()->notNull(),
            'variantId' => $this->integer()->notNull(),
            'title' => $this->string()->notNull(),
            'sku' => $this->string(),
            'oldPrice' => $this->decimal(14, 4)->notNull(),
            'newPrice' => $this->decimal(14, 4)->notNull(),
            'oldPromotionalPrice' => $this->decimal(14, 4),
            'newPromotionalPrice' => $this->decimal(14, 4),
            'ruleName' => $this->string()->notNull(),
            'ruleIndex' => $this->integer()->notNull(),
            'ruleLabel' => $this->string()->notNull(),
            'ruleSnapshot' => $this->text(),
            'updateHistory' => $this->json(),
            'appliedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, $table, ['variantId', 'effectiveDate'], false);
        $this->createIndex(null, $table, ['effectiveDate', 'appliedAt'], false);
        return true;
    }
    public function safeDown(): bool
    {
        $this->dropTableIfExists(PriceSchedule::tableName());
        return true;
    }
}
