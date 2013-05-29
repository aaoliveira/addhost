<?
ini_set("display_errors", "Off");
ini_set("error_reporting",E_ALL ^ E_NOTICE ^ E_WARNING);

define("APACHE_VHOST_PATH","/etc/apache2/sites-enabled");
define("APACHE_GROUP","www-data");
define("CURRENT_USER","evaldobarbosa");
define("HOSTS_FILE","/etc/hosts");

class AddHost {
  private $ip;
	private $hostname;
	private $folder;
	private $folderCreated = false;
	private $htaccessCreation = false;
	private $log = array();
	private $rollback = array();

	function __construct($ip,$hostname,$folder,$htacess = false) {
		$this->setIP( $ip );
		$this->setHostname($hostname);
		$this->setFolder($folder);
		$this->htaccessCreation = $htacess;
	}

	function setIP($value) {
		$this->ip = $value;
	}

	function setHostname($value) {
		$this->hostname = $value;
	}

	function setFolder($value) {
		$this->folder = $value;
	}

	function getPublicFolder() {
		return "{$this->folder}/public";
	}

	private function createVHost() {
		$filename = APACHE_VHOST_PATH . "/" . strtolower($this->hostname) . ".conf";
		if ( file_exists($filename) ) {
			throw new Exception("VHost já configurado", 1);
		}

		$this->log['vhost'] = "CONFIGURANDO VIRTUALHOST";

		$vhc = array(); //virtual_host_content
		$vhc[] = "### CREATED BY ADDHOST: " . date("Y-m-d H:i:s") . "###";
		$vhc[] = "NameVirtualHost {$this->ip}:80";
		$vhc[] = "<VirtualHost {$this->ip}:80>";
		$vhc[] = "\tServerAdmin hostmaster@{$this->hostname}";
		$vhc[] = "\tServerName {$this->hostname}";
		$vhc[] = "\tDocumentRoot {$this->getPublicFolder()}";
		$vhc[] = "\t<Directory />";
		$vhc[] = "\t\tOptions Indexes FollowSymLinks MultiViews";
		$vhc[] = "\t\tAllowOverride All";
		$vhc[] = "\t\tOrder allow,deny";
		$vhc[] = "\t\tAllow from all";
		$vhc[] = "\t</Directory>";
		$vhc[] = "</VirtualHost>";

		$f = file_put_contents($filename, implode("\n", $vhc));

		unset($vhc);

		if ( !$f ) {
			$this->rollback['vhost'] = $filename;
			throw new Exception("Erro ao criar arquivo de vhost", 1);
		}
	}

	private function appendHostName() {
		$this->log['hostname'] = "CONFIGURANDO HOST";

		$contents = file_get_contents(HOSTS_FILE);
		$hostname .= "\n{$this->ip}\t{$this->hostname}";

		$pos = strpos($contents, "\t{$this->hostname}");
		if ( $pos === true ) {
			$this->rollback['hosts'] = true;
			throw new Exception("Erro ao adicionar host no arquivo", 1);
		}

		$contents .= $hostname;
		$f = file_put_contents( dirname(__FILE__ ). "/hosts.temp" , $contents);

		if ( !$f ) {
			$this->rollback['hosts'] = true;
			throw new Exception("Erro ao adicionar host no arquivo", 1);			
		}

		$this->log['hostname1'] = "HOSTNAME CONFIGURADO";
	}

	private function createFolder() {
		$f1 = mkdir($this->folder, 0775, true);
		$p1 = mkdir($this->getPublicFolder(), 0775, true);

		//Folder
		$f2 = chown($this->folder, CURRENT_USER);
		$p2 = chgrp($this->folder, APACHE_GROUP);

		$f3 = chown($this->getPublicFolder(), CURRENT_USER);
		$p3 = chgrp($this->getPublicFolder(), APACHE_GROUP);

		if ( !$f1 || !$f2 || !$f3 || !$p1 || !$p2 || !$p3 ) {
			$this->rollback['folder'] = true;
			throw new Exception("Erro ao criar pastas do host", 1);			
		}
	}

	private function createHTAccess() {
		$this->log['htaccess'] = "CONFIGURANDO HTACCESS\n";	

		$vhc = array(); //virtual_host_content
		$vhc[] = "### CREATED BY ADDHOST: " . date("Y-m-d H:i:s") . "###";
		$vhc[] = "Options +FollowSymlinks";
		$vhc[] = "RewriteEngine On";

		$vhc[] = "RewriteCond %{REQUEST_URI} !\.(gif|jpg|png)$";
		$vhc[] = "RewriteCond %{REQUEST_FILENAME} !-f";
		$vhc[] = "RewriteCond %{REQUEST_FILENAME} !-d";
		$vhc[] = "RewriteRule (.*) /index.php [L]";

		$f = file_put_contents("{$this->getPublicFolder()}/.htaccess", implode("\n", $vhc));

		unset($vhc);

		if ( !$f ) {
			$this->rollback['htaccess'] = true;
			throw new Exception("Erro ao criar htaccess", 1);
		}

		//htaccess
		chown("{$this->getPublicFolder()}/.htaccess", CURRENT_USER);
		chgrp("{$this->getPublicFolder()}/.htaccess", APACHE_GROUP);

		$this->log['htaccess1'] = "SEU HTACCESS FOI CRIADO CORRETAMENTE";
	}

	private function validateIP() {
		if ( !preg_match("(^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$)", $this->ip) ) {
			throw new Exception("O primeiro parâmetro deve ser o IP da aplicação a ser configurado");
		}
	}

	function getLog() {
		return $this->log;
	}

	function run() {
		try {
			$this->validateIP();
			$this->createVHost();
			$this->appendHostName();
			$this->createFolder();

			if ( $this->htaccessCreation ) {
				$this->createHTAccess();
			}

			$filename = dirname( __FILE__ ). "/hosts.temp";
			if ( file_exists(filename) ) {
				copy( $filename, HOSTS_FILE );
				unset( $filename );
				echo "ARQUIVOS COPIADOS\n";
			}

			return array("success"=>$this->log);
		} catch ( Exception $e ) {
			if ( isset($this->rollback['vhost']) ) {
				unset( $this->rollback['vhost'] );
			}

			if ( isset($this->rollback['hosts']) ) {
				$contents = file_get_contents(HOSTS_FILE);
				$contents .= str_replace("\n{$ip}\t{$server_name}","",$contents);
				$f = file_put_contents("/etc/hosts", $contents);
			}

			if ( isset($this->rollback['folder']) ) {
				$public = $this->getPublicFolder();
				unset( $public );
				unset( $this->folder );
			}

			if ( isset($this->rollback['htaccess']) ) {
				unset( $this->rollback['htaccess'] );
			}

			return array("error"=>$e->getMessage());
		}
	}
}
