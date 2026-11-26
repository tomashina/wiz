<?php
class ControllerExtensionModuleEmailTemplateCron extends Controller {
	public function index() {
		if (!defined("OPENCART_CLI_MODE") || OPENCART_CLI_MODE === FALSE) {
			exit;
		}

		//if (empty($this->request->get['token']) || $this->request->get['token'] != $this->config->get('emailtemplate_token')){
		if (empty($_SERVER['argv'][3]) || $_SERVER['argv'][3] != $this->config->get('emailtemplate_token')) {
			trigger_error('Cron token error: ' . print_r($_SERVER['argv'], true));
			exit;
		}

		$this->load->model('extension/module/emailtemplate');

		$logs = $this->model_extension_module_emailtemplate->getQueuedLogs();

		if ($logs) {
			foreach ($logs as $i => $log) {
				$mail = new Mail($this->config->get('config_mail_engine'));
				$mail->protocol = !empty($log['emailtemplate_log_protocol']) ? $log['emailtemplate_log_protocol'] : $this->config->get('config_mail_protocol');
				$mail->parameter = !empty($log['emailtemplate_log_parameter']) ? $log['emailtemplate_log_parameter'] : $this->config->get('config_mail_parameter');

				$smtp = empty($log['emailtemplate_log_smtp']) ? [] : unserialize(base64_decode($log['emailtemplate_log_smtp']), ['allowed_classes' => false]);
				$mail->smtp_hostname = isset($smtp['hostname']) ? $smtp['hostname'] : $this->config->get('config_mail_smtp_hostname');
				$mail->smtp_username = isset($smtp['username']) ? $smtp['username'] : $this->config->get('config_mail_smtp_username');
				$mail->smtp_password = isset($smtp['password']) ? $smtp['password'] : $this->config->get('config_mail_smtp_password');
				$mail->smtp_port = isset($smtp['port']) ? $smtp['port'] : $this->config->get('config_mail_smtp_port');
				$mail->smtp_timeout = isset($smtp['timeout']) ? $smtp['timeout'] : $this->config->get('config_mail_smtp_timeout');

				$mail->setTo($log['emailtemplate_log_to']);
				$mail->setFrom($log['emailtemplate_log_from']);

				$file = DIR_CACHE . 'mail_queue/' . $log['emailtemplate_log_enc'];

				if (is_file($file)) {
					$html = file_get_contents($file);
				} else {
					// Load template if html not found
					$template_load = array(
						'emailtemplate_id' => $log['emailtemplate_id'],
						'store_id' => $log['store_id'],
						'language_id' => $log['language_id']
					);

					$template = $this->model_extension_module_emailtemplate->load($template_load);

					if (!$template) {
						$template_load['emailtemplate_id'] = 1;
						$template = $this->model_extension_module_emailtemplate->load($template_load);
						if (!$template) continue;
					}

					$template->build();
					$template->fetch(null, $log['emailtemplate_log_content']);
					$html = $template->getHtml();
				}

				$mail->setHtml($html);

				if ($log['emailtemplate_log_sender']) {
					$mail->setSender(html_entity_decode($log['emailtemplate_log_sender'], ENT_QUOTES, 'UTF-8'));
				}

				if ($log['emailtemplate_log_subject']) {
					$mail->setSubject(html_entity_decode($log['emailtemplate_log_subject'], ENT_QUOTES, 'UTF-8'));
				}

				if (!empty($log['emailtemplate_log_text'])) {
					$mail->setText(html_entity_decode($log['emailtemplate_log_text'], ENT_QUOTES, 'UTF-8'));
				}

				if ($log['emailtemplate_log_cc']) {
					$mail->setCc($log['emailtemplate_log_cc']);
				}

				if ($log['emailtemplate_log_bcc']) {
					$mail->setBcc($log['emailtemplate_log_bcc']);
				}

				if (!empty($log['emailtemplate_log_attachment'])) {
					$log['emailtemplate_log_attachment'] = unserialize(base64_decode($log['emailtemplate_log_attachment']));
					foreach ($log['emailtemplate_log_attachment'] as $attachment) {
						if (is_file($attachment) && (($log['emailtemplate_key'] ?? '') !== 'order.customer' || basename($attachment) !== 'legal-guarantee-notice-hr.png')) {
							$mail->addAttachment($attachment);
						}
					}
				}

				$mail->setMailQueue(false);

				if (($log['emailtemplate_key'] ?? '') === 'order.customer') {
					require_once DIR_SYSTEM . 'library/wiz_guarantee.php';
					$storeUrl = $log['store_ssl'] ?: ($log['store_url'] ?: HTTPS_SERVER);
					WizGuarantee::restoreOrderMail($mail, $html, $storeUrl);
				}
				$mail->send();
				if (is_file($file)) { unlink($file); }

				$this->db->query("UPDATE " . DB_PREFIX . "emailtemplate_logs SET emailtemplate_log_sent = NOW(), emailtemplate_log_is_sent = 1 WHERE emailtemplate_log_id = " . (int)$log['emailtemplate_log_id']);
			}

			$this->cache->delete('emailtemplate_mail_queue');
		}
	}

}