<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStatusCatalogTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'module'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'code'        => ['type' => 'VARCHAR', 'constraint' => 40],
            'label'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'meaning'     => ['type' => 'VARCHAR', 'constraint' => 500],
            'badge_class' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'secondary'],
            'sort_order'  => ['type' => 'INT', 'default' => 0],
            'is_terminal' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['module', 'code']);
        $this->forge->addKey(['module', 'status', 'sort_order']);
        $this->forge->createTable('status_definitions', true);

        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'category'   => ['type' => 'VARCHAR', 'constraint' => 60],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 60],
            'label'      => ['type' => 'VARCHAR', 'constraint' => 150],
            'meaning'    => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sort_order' => ['type' => 'INT', 'default' => 0],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['category', 'code']);
        $this->forge->addKey(['category', 'status', 'sort_order']);
        $this->forge->createTable('lookup_values', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('lookup_values', true);
        $this->forge->dropTable('status_definitions', true);
    }
}
