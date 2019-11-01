<?php

namespace App\Command;

use App\Controller\Files\FileUploadController;
use App\Controller\Modules\Files\MyFilesController;
use App\Controller\Modules\Images\MyImagesController;
use App\Controller\Utils\Env;
use App\Services\Database\DatabaseExporter;
use App\Services\Files\Archivizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CronMakeBackupCommand extends Command
{
    const BACKUP_TYPE_SQL   = 'sql';
    const BACKUP_TYPE_FILES = 'files';

    const BACKUP_DIRECTORY  = '/home/volmarg/Partycje/Dane/pms_db_backup';
    const BACKUP_DATABASE_FILENAME   = 'pmsSqlBackup';
    const BACKUP_FILES_FILENAME      = 'files';

    const OPTION_SKIP_FILES             = 'skip-files';
    const OPRION_SKIP_UPLOAD_MODULE   = 'skip-upload-module';

    const PUBLIC_DIR_ROOT               = DOT . DIRECTORY_SEPARATOR . 'public';

    const ALL_BACKUPS_TYPES = [
        self::BACKUP_TYPE_FILES,
        self::BACKUP_TYPE_SQL,
    ];

    protected static $defaultName = 'cron:make-backup';

    /**
     * @var DatabaseExporter $database_exporter
     */
    private $database_exporter;

    /**
     * @var Archivizer $archivizer
     */
    private $archivizer;

    public function __construct(DatabaseExporter $database_exporter, Archivizer $archivizer, string $name = null) {
        parent::__construct($name);
        $this->database_exporter = $database_exporter;
        $this->archivizer        = $archivizer;
    }

    protected function configure()
    {

        $backup_types         = implode(', ', self::ALL_BACKUPS_TYPES);
        $upload_modules_names = implode(', ', array_keys(FileUploadController::MODULES_UPLOAD_DIRS_FOR_MODULES_NAMES));

        $this
            ->setDescription('This command allows to make backup of: ' . $backup_types)
            ->addOption(self::OPTION_SKIP_FILES, null,InputOption::VALUE_NONE, 'If set - will skip backing up the upload directory.')
            ->addOption(self::OPRION_SKIP_UPLOAD_MODULE, null, InputOption::VALUE_REQUIRED,
              "
                Will skip backup of files for given upload based module. Possible values: [{$upload_modules_names}]
                Use example: --skip-files=My\ Images,My\ Files (escaped spacebars).
              "
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $io->note("Started backup process");
        {

            $option_skip_files = $input->getOption(self::OPTION_SKIP_FILES);

            if ($option_skip_files) {
                $io->note(sprintf("Files backup will be skipped"));
            }

            if( !$option_skip_files ){

                try{
                    $skipped_modules = explode(',', $input->getOption(self::OPRION_SKIP_UPLOAD_MODULE));
                }catch(\Exception $e){
                    $io->error("Could not parse data for skipped modules. Did You provided valid values like in example?");
                    return false;
                }

                $this->backupFiles($io, $skipped_modules);
            }

            $this->backupDatabase($io);

        }
        $io->note("Backup process has been completed");
    }

    /**
     * This function creates database dump
     * @param SymfonyStyle $io
     */
    private function backupDatabase(SymfonyStyle $io){
        $this->database_exporter->setFileName(self::BACKUP_DATABASE_FILENAME);
        $this->database_exporter->setBackupDirectory(self::BACKUP_DIRECTORY);
        $this->database_exporter->runInternalDatabaseExport();
        $export_message = $this->database_exporter->getExportMessage();

        if( $this->database_exporter->isExportedSuccessfully() ){
            $io->success($export_message);
        }else{
            $io->warning($export_message);
        }
    }

    /**
     * This function creates zip archive
     * @param SymfonyStyle $io
     * @param array $skipped_modules
     */
    private function backupFiles(SymfonyStyle $io, array $skipped_modules = []){

        $upload_dirs_for_modules = [
          MyImagesController::MODULE_NAME => self::PUBLIC_DIR_ROOT . DIRECTORY_SEPARATOR . Env::getImagesUploadDir(),
          MyFilesController::MODULE_NAME  => self::PUBLIC_DIR_ROOT . DIRECTORY_SEPARATOR . Env::getFilesUploadDir(),
        ];

        foreach($skipped_modules as $skipped_module){
            if( array_key_exists($skipped_module, $upload_dirs_for_modules) ){
                unset($upload_dirs_for_modules[$skipped_module]);
            }
        }

        $this->archivizer->setBackupDirectory(self::BACKUP_DIRECTORY);
        $this->archivizer->setZipRecursively(true);
        $this->archivizer->setArchiveName(self::BACKUP_FILES_FILENAME);
        $this->archivizer->setDirectoriesToZip($upload_dirs_for_modules);

        $this->archivizer->zip();
        $message = $this->archivizer->getZippingStatus();

        if( $this->archivizer->isZippedSuccessfully() ){
            $io->success($message);
        }else{
            $io->warning($message);
        }
    }
}
