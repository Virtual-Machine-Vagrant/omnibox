<?php
namespace Uberstead\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Console\Question\Question;
use Uberstead\Container\Container;

class BaseCommand extends Command
{
    /**
     * @var Container
     */
    private $container;

    /**
     * @param Container $container
     */
    public function setContainer($container)
    {
        $this->container = $container;
    }

    /**
     * @return Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    protected function checkConfig(InputInterface $input, OutputInterface $output)
    {
        if (posix_getuid() != 0) {
            $output->writeln('This command needs access to /etc/hosts and /etc/exports. Please run as root (sudo).');
            die();
        }

        exec('echo ~', $out);
        if (!file_exists($out[0].'/.ssh/id_rsa.pub')) {
            $output->writeln('It seems like you don\'t have any SSH keys. Run <question>ssh-keygen -t rsa -C "your_email@example.com"</question> to generate keys.');
            die();
        }

        $filename = 'uberstead.yaml';
        if (!file_exists($filename)) {

            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('<question>uberstead.yaml does not exist! Would you like to generate it? [y]</question>', true);

            $output->writeln('');

            if ($helper->ask($input, $output, $question)) {
                $question = new Question('Which IP would you like to assign to the server? [192.168.10.10]: ', '192.168.10.10');
                $ip = $helper->ask($input, $output, $question);
                $question = new Question('Amount of memory [2048]: ', '2048');
                $memory = $helper->ask($input, $output, $question);
                $question = new Question('Number of CPU cores [1]: ', '1');
                $cpu = $helper->ask($input, $output, $question);
            }

            $yaml = <<<EOF
ip: "{$ip}"
memory: {$memory}
cpus: {$cpu}

authorize: ~/.ssh/id_rsa.pub

keys:
    - ~/.ssh/id_rsa

defaultfoldertype: nfs

sites:
EOF;
            file_put_contents($filename, $yaml);
            chmod($filename, 0664);
            chown($filename, end(explode('/', $out[0])));
        }
    }

    public function setDbHintInParametersYml($directory, $name, $ip)
    {
        $name = str_replace(" ", "_", $name);
        $name = strtolower(preg_replace("/[^a-zA-Z0-9_]+/", "", $name));

        $username = "homestead";
        $password = "secret";

        $conn = new \mysqli($ip, $username, $password);
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }

        $sql = "CREATE DATABASE ".$name;
        if ($conn->query($sql) === TRUE) {
//            echo "Database created successfully";
        } else {
//            echo "Error creating database: " . $conn->error;
        }

        $conn->close();


        $parametersYml = $directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'parameters.yml';
        if (file_exists($parametersYml)) {
            $comment = "#Uberstead Config Hint#    ";
            $fileContents = file($parametersYml);
            foreach ($fileContents as $key => $line) {
                if (strpos($line, $comment) !== false) {
                    unset($fileContents[$key]);
                }
            }

            $fileContents[] = $comment . "database_host: 127.0.0.1\n";
            $fileContents[] = $comment . "database_port: 3306\n";
            $fileContents[] = $comment . "database_name: ".$name."\n";
            $fileContents[] = $comment . "database_user: homestead\n";
            $fileContents[] = $comment . "database_password: secret\n";

            $fileContents = implode("", $fileContents);
            $fileContents = trim($fileContents, "\n")."\n";
            file_put_contents($parametersYml, $fileContents);
        }
    }

    protected function getConfig()
    {
        $yaml = new Parser();
        $array = $yaml->parse(file_get_contents('uberstead.yaml'));

        if (!isset($array['sites'])) {
            $array['sites'] = array();
        }

        return $array;
    }

    protected function saveConfig($array)
    {
        $dumper = new Dumper();
        $yaml = $dumper->dump($array, 3);
        file_put_contents('uberstead.yaml', $yaml);
    }

    public function runProvision(InputInterface $input, OutputInterface $output)
    {
        $command = new UbersteadProvisionCommand();
        $command->setApplication($this->getApplication());
        $command->run($input, $output);
    }

    public function updateNfsShares(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('<question>You have changed the shared directories. You need to run "vagrant reload" to apply the changes. Would you like to do it now? [y]</question>', true);
        if ($helper->ask($input, $output, $question)) {
            $output->writeln('<info>Running "vagrant reload"...</info>');
            $this->runCommandWithProgressBar($input, $output, 'su $SUDO_USER -c "vagrant reload"', 30);
        }
    }

    public function runCommandWithProgressBar(InputInterface $input, OutputInterface $output, $command, $expectedLinesNum = 50)
    {
        $isVerbose = (OutputInterface::VERBOSITY_VERBOSE == $output->getVerbosity());

        if (!$isVerbose) {
            /** @var ProgressHelper $progress */
            $progress = $this->getHelper('progress');
            $progress->setBarWidth(60);
            $progress->start($output, $expectedLinesNum);
        }

        $process = new Process($command);
        $process->setTimeout(null);

        $error = false;
        $bufferArr = array();
        $process->run(function ($type, $buffer) use (&$progress, &$error, &$bufferArr, &$output, &$isVerbose) {
            if ($isVerbose) {
                if (Process::ERR === $type) {
                    $output->write('<error>'.$buffer.'</error>');
                } else {
                    $output->write('<info>'.$buffer.'</info>');
                }
            } else {
                if (Process::ERR === $type) {
                    $error = true;
                    $bufferArr[] = '<error>'.$buffer.'</error>';
                } else {
                    $bufferArr[] = $buffer;
                }
                $progress->advance();
            }
        });

        if (!$isVerbose) {
            $progress->setCurrent($expectedLinesNum);
            $progress->finish();

            if ($error) {
                $output->writeln(implode('', $bufferArr));
            }
        }
    }

    public function addSite(InputInterface $input, OutputInterface $output, $name = null, $directory = null, $webroot = null)
    {
        $array = $this->getConfig();

        $validator = $this->getContainer()->getValidator();
        $validator->setConfigArray($array);

        $helper = $this->getHelper('question');

        if ($name === null) {
            $name = $helper->ask($input, $output, $validator->createSiteNameQuestion());
        }

        $domain = $helper->ask($input, $output, $validator->createDomainQuestion());

        if ($directory === null) {
            $directory = $helper->ask($input, $output, $validator->createDirectoryQuestion());
        }

        if ($webroot === null) {
            $webroot = $helper->ask($input, $output, $validator->createWebrootQuestion($directory));
        }

        $array['sites'][] = [
            'name' => $name,
            'domain' => $domain,
            'directory' => $directory,
            'webroot' => $webroot,
        ];

        $this->saveConfig($array);
        $this->setDbHintInParametersYml($directory, $name, $array['ip']);

        $dumper = new Dumper();
        $yaml = $dumper->dump($array, 3);
        file_put_contents('uberstead.yaml', $yaml);
    }
}