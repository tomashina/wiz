<?php
class WizWithdrawals {
    private $registry;
    private $db;
    private $table;

    public function __construct($registry) {
        $this->registry = $registry;
        $this->db = $registry->get('db');
        $this->table = DB_PREFIX . 'wiz_withdrawal';
    }

    public function ensureTable() {
        if (!$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(str_replace('_', '\\_', $this->table)) . "'")->num_rows) {
            $this->db->query("CREATE TABLE IF NOT EXISTS `" . $this->table . "` (
                withdrawal_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, submission CHAR(64) NOT NULL UNIQUE, full_name VARCHAR(160) NOT NULL,
                email VARCHAR(254) NOT NULL, order_ref VARCHAR(160) NOT NULL, items TEXT NOT NULL, submitted_at DATETIME NOT NULL,
                customer_sent TINYINT NOT NULL DEFAULT 0, admin_sent TINYINT NOT NULL DEFAULT 0,
                INDEX (submitted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }

    public static function fields($input) {
        $row = [];
        foreach (['full_name'=>160,'email'=>254,'order_ref'=>160,'items'=>2000] as $k=>$limit) {
            $value = is_scalar($input[$k] ?? null) ? trim(html_entity_decode($input[$k], ENT_QUOTES, 'UTF-8')) : '';
            if (($k !== 'items' && $value === '') || mb_strlen($value) > $limit) { throw new InvalidArgumentException('Provjerite ime, oznaku narudžbe i e-mail. Popis artikala može imati do 2000 znakova.'); }
            $row[$k] = $value;
        }
        if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Upišite valjanu adresu e-pošte za potvrdu.'); }
        return $row;
    }

    public function record($nonce, array $row) {
        $this->ensureTable();
        $values = [];
        foreach (['full_name','email','order_ref','items'] as $key) { $values[] = $key . "='" . $this->db->escape($row[$key]) . "'"; }
        $this->db->query("INSERT INTO " . $this->table . " SET submission='" . hash('sha256',$nonce) . "', submitted_at=UTC_TIMESTAMP(), " .
            implode(',', $values) . " ON DUPLICATE KEY UPDATE withdrawal_id=LAST_INSERT_ID(withdrawal_id)");
        return (int)$this->db->getLastId();
    }

    public function listing($offset = 0, $limit = 50) {
        $this->ensureTable();
        return $this->db->query("SELECT withdrawal_id,full_name,email,order_ref,items,submitted_at,customer_sent,admin_sent FROM " . $this->table .
            " ORDER BY withdrawal_id DESC LIMIT " . (int)$offset . "," . (int)$limit)->rows;
    }

    public static function localTime($utc) {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Zagreb'))->format('d.m.Y. H:i:s T');
    }

    public static function receipt(array $r) {
        return "Potvrda primitka izjave o jednostranom raskidu ugovora\n\n" .
            "Broj zahtjeva: WIZ-" . $r['withdrawal_id'] . "\nZaprimljeno: " . self::localTime($r['submitted_at']) .
            "\nIme i prezime: " . $r['full_name'] . "\nE-mail za potvrdu: " . $r['email'] .
            "\nOznaka narudžbe ili ugovora: " . $r['order_ref'] .
            "\n\nIzjava: Jednostrano raskidam ugovor za " . ($r['items'] === '' ? 'cijelu navedenu narudžbu.' : "sljedeće artikle:\n" . $r['items']) . "\n";
    }

    public function notify($id) {
        // Serialize attempts per receipt; a retry only sends outstanding recipients.
        $key = 'wizwithdraw_' . substr(hash('sha256', DB_DATABASE . ':' . $this->table . ':' . (int)$id), 0, 40);
        if (!$this->db->query("SELECT GET_LOCK('" . $key . "', 5) ok")->row['ok']) { return; }
        try {
            $r = $this->db->query('SELECT * FROM ' . $this->table . ' WHERE withdrawal_id=' . (int)$id)->row;
            if (!$r) { return; }
            $cfg = $this->registry->get('config');
            foreach (['customer_sent'=>$r['email'], 'admin_sent'=>$cfg->get('config_email')] as $flag=>$to) {
                if ($r[$flag]) { continue; }
                try {
                    $mail = new Mail($cfg->get('config_mail_engine'));
                    foreach (['parameter','smtp_hostname','smtp_username','smtp_port','smtp_timeout'] as $setting) { $mail->$setting = $cfg->get('config_mail_' . $setting); }
                    $mail->smtp_password = html_entity_decode((string)$cfg->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
                    $mail->setFrom($cfg->get('config_email'));
                    $mail->setSender(html_entity_decode((string)$cfg->get('config_name'), ENT_QUOTES, 'UTF-8'));
                    $mail->setTo($to);
                    $mail->setSubject('WIZ-' . (int)$id . ' – potvrda jednostranog raskida ugovora');
                    $mail->setText(self::receipt($r));
                    $mail->send();
                    $this->db->query("UPDATE " . $this->table . " SET " . $flag . "=1 WHERE withdrawal_id=" . (int)$id);
                } catch (Throwable $e) { $this->registry->get('log')->write('WIZ raskid #' . (int)$id . ': neuspjela dostava (' . $flag . ').'); }
            }
        } finally { $this->db->query("SELECT RELEASE_LOCK('" . $key . "')"); }
    }
}
