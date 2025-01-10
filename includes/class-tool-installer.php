class SEWN_Tool_Installer {
    private $logger;
    private $os_type;
    private $package_managers = [
        'linux' => ['apt-get', 'yum', 'dnf'],
        'darwin' => ['brew'],
        'win' => ['choco']
    ];
    
    public function __construct($logger) {
        $this->logger = $logger;
        $this->os_type = $this->detect_os();
    }
    
    public function install_tool($tool_id) {
        if (!current_user_can('manage_options')) {
            throw new Exception('Insufficient permissions');
        }
        
        $installation_map = [
            'wkhtmltoimage' => [
                'linux' => ['apt-get' => 'wkhtmltopdf'],
                'darwin' => ['brew' => 'wkhtmltopdf'],
                'win' => ['choco' => 'wkhtmltopdf']
            ],
            'puppeteer' => [
                'command' => 'npm install puppeteer',
                'type' => 'npm'
            ]
            // Add more tools...
        ];
        
        if (!isset($installation_map[$tool_id])) {
            throw new Exception('Unsupported tool');
        }
        
        return $this->execute_installation($tool_id, $installation_map[$tool_id]);
    }
    
    private function detect_os() {
        if (defined('PHP_OS_FAMILY')) {
            return strtolower(PHP_OS_FAMILY);
        }
        return strtolower(PHP_OS);
    }
    
    private function execute_installation($tool_id, $config) {
        try {
            // Track installation status
            update_option("sewn_installing_{$tool_id}", true);
            
            if (isset($config['type']) && $config['type'] === 'npm') {
                return $this->install_npm_package($config['command']);
            }

            $package_manager = $this->detect_package_manager();
            if (!$package_manager) {
                throw new Exception('No supported package manager found');
            }

            $package = $config[$this->os_type][$package_manager] ?? null;
            if (!$package) {
                throw new Exception("No package defined for {$this->os_type} using {$package_manager}");
            }

            // Execute installation with proper escaping
            $command = $this->build_install_command($package_manager, $package);
            $result = $this->execute_command($command);

            // Verify installation
            if (!$this->verify_installation($tool_id)) {
                throw new Exception('Installation verification failed');
            }

            return true;

        } catch (Exception $e) {
            $this->logger->error('Installation failed', [
                'tool' => $tool_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            delete_option("sewn_installing_{$tool_id}");
        }
    }
    
    private function detect_package_manager() {
        $managers = $this->package_managers[$this->os_type] ?? [];
        foreach ($managers as $manager) {
            if ($this->execute_command("which {$manager}")) {
                return $manager;
            }
        }
        return null;
    }
    
    private function build_install_command($manager, $package) {
        $commands = [
            'apt-get' => "DEBIAN_FRONTEND=noninteractive apt-get install -y",
            'yum' => "yum install -y",
            'brew' => "brew install",
            'choco' => "choco install -y"
        ];
        return escapeshellcmd($commands[$manager] . ' ' . $package);
    }
    
    private function verify_installation($tool_id) {
        $detector = new SEWN_Screenshot_Service_Detector($this->logger);
        $services = $detector->detect_services(true);
        return isset($services['services'][$tool_id]) && $services['services'][$tool_id]['available'];
    }
} 