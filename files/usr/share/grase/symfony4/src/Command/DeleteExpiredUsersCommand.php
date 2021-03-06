<?php

namespace App\Command;

use App\Entity\Radius\Check;
use App\Entity\UpdateUserData;
use App\Util\GraseConsoleStyle;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cron task to find all expired users and delete them to keep the system clean
 */
class DeleteExpiredUsersCommand extends Command
{
    protected static $defaultName = 'grase:cron:deleteExpiredUsers';

    /** @var EntityManagerInterface */
    private $em;

    /** @var LoggerInterface */
    private $auditLogger;

    /** @var LoggerInterface */
    private $logger;

    /** @var TranslatorInterface */
    private $translator;

    /**
     * @param EntityManagerInterface $entityManager
     * @param Logger                 $auditLogger
     * @param LoggerInterface        $logger
     * @param TranslatorInterface    $translator
     */
    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $auditLogger, LoggerInterface $logger, TranslatorInterface $translator)
    {
        parent::__construct();
        $this->em = $entityManager;
        $this->auditLogger = $auditLogger;
        $this->translator = $translator;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Delete user accounts that have been expired for more than 2 months')
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new GraseConsoleStyle($input, $output);
        // Find the list of users, then run PHP code to do the actual deletes

        $query = $this->em->getRepository(Check::class)
            ->createQueryBuilder('c')
            ->select('c', 'u')
            ->join('c.user', 'u')
            ->andWhere('c.attribute = :expirationAttribute')
            ->setParameter('expirationAttribute', Check::EXPIRATION)
            ->andWhere("strtodate(c.value, '%M %d %Y %H:%i:%s') < :expiryCutOff")
            ->setParameter('expiryCutOff', new \DateTime('-2 months'))
            ->getQuery();

        $results = $query->getResult();
        /** @var Check $result */
        foreach ($results as $result) {
            $user = $result->getUser();

            $this->auditLogger->info(
                'grase.cron.audit.deleteExpiredUser',
                ['user' => $user->getUsername(), 'expiry' => $result->getValue()]
            );
            $io->success(
                $this->translator->trans(
                    'grase.cron.output.deleteExpiredUser',
                    ['user' => $user->getUsername(), 'expiry' => $result->getValue()]
                )
            );

            UpdateUserData::deleteUser($user, $this->em);
        }
    }
}
