<?php declare(strict_types=1);

namespace App\Controller\API;

use App\Doctrine\DBAL\Types\JudgeTaskType;
use App\Entity\Contest;
use App\Entity\Executable;
use App\Entity\ExecutableFile;
use App\Entity\InternalError;
use App\Entity\Judgehost;
use App\Entity\JudgeTask;
use App\Entity\Judging;
use App\Entity\JudgingRun;
use App\Entity\JudgingRunOutput;
use App\Entity\Rejudging;
use App\Entity\Submission;
use App\Entity\SubmissionFile;
use App\Entity\TestcaseContent;
use App\Service\BalloonService;
use App\Service\ConfigurationService;
use App\Service\DOMJudgeService;
use App\Service\EventLogService;
use App\Service\RejudgingService;
use App\Service\ScoreboardService;
use App\Service\SubmissionService;
use App\Utils\Utils;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use Nelmio\ApiDocBundle\Annotation\Model;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @Rest\Route("/judgehosts")
 * @OA\Tag(name="Judgehosts")
 */
class JudgehostController extends AbstractFOSRestController
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var DOMJudgeService
     */
    protected $dj;

    /**
     * @var ConfigurationService
     */
    protected $config;

    /**
     * @var EventLogService
     */
    protected $eventLogService;

    /**
     * @var ScoreboardService
     */
    protected $scoreboardService;

    /**
     * @var SubmissionService
     */
    protected $submissionService;

    /**
     * @var BalloonService
     */
    protected $balloonService;

    /**
     * @var RejudgingService
     */
    protected $rejudgingService;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        EntityManagerInterface $em,
        DOMJudgeService $dj,
        ConfigurationService $config,
        EventLogService $eventLogService,
        ScoreboardService $scoreboardService,
        SubmissionService $submissionService,
        BalloonService $balloonService,
        RejudgingService $rejudgingService,
        LoggerInterface $logger
    ) {
        $this->em                = $em;
        $this->dj                = $dj;
        $this->config            = $config;
        $this->eventLogService   = $eventLogService;
        $this->scoreboardService = $scoreboardService;
        $this->submissionService = $submissionService;
        $this->balloonService    = $balloonService;
        $this->rejudgingService  = $rejudgingService;
        $this->logger            = $logger;
    }

    /**
     * Get judgehosts
     * @Rest\Get("")
     * @IsGranted("ROLE_JURY")
     * @OA\Response(
     *     response="200",
     *     description="The judgehosts",
     *     @OA\JsonContent(type="array", @OA\Items(ref=@Model(type=Judgehost::class)))
     * )
     * @OA\Parameter(
     *     name="hostname",
     *     in="query",
     *     description="Only show the judgehost with the given hostname",
     *     @OA\Schema(type="string")
     * )
     */
    public function getJudgehostsAction(Request $request) : array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j');

        if ($request->query->has('hostname')) {
            $queryBuilder
                ->andWhere('j.hostname = :hostname')
                ->setParameter(':hostname', $request->query->get('hostname'));
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Add a new judgehost to the list of judgehosts.
     * Also restarts (and returns) unfinished judgings.
     * @Rest\Post("")
     * @IsGranted("ROLE_JUDGEHOST")
     * @OA\Response(
     *     response="200",
     *     description="The returned unfinished judgings",
     *     @OA\JsonContent(
     *         type="array",
     *         @OA\Items(
     *             type="object",
     *             properties={
     *                 @OA\Property(property="jobid", type="integer"),
     *                 @OA\Property(property="submitid", type="integer")
     *             }
     *         )
     *     )
     * )
     * @throws NonUniqueResultException
     */
    public function createJudgehostAction(Request $request) : array
    {
        if (!$request->request->has('hostname')) {
            throw new BadRequestHttpException('Argument \'hostname\' is mandatory');
        }

        $hostname = $request->request->get('hostname');

        /** @var Judgehost|null $judgehost */
        $judgehost = $this->em->createQueryBuilder()
            ->from(Judgehost::class, 'j')
            ->select('j')
            ->andWhere('j.hostname = :hostname')
            ->setParameter(':hostname', $hostname)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$judgehost) {
            $judgehost = new Judgehost();
            $judgehost->setHostname($hostname);
            $this->em->persist($judgehost);
            $this->em->flush();
        }

        // If there are any unfinished judgings in the queue in my name, they will not be finished.
        // Give them back.
        /** @var Judging[] $judgings */
        $judgings = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->innerJoin('j.judgehost', 'jh')
            ->leftJoin('j.rejudging', 'r')
            ->select('j')
            ->andWhere('jh.hostname = :hostname')
            ->andWhere('j.endtime IS NULL')
            ->andWhere('j.valid = 1 OR r.valid = 1')
            ->setParameter(':hostname', $hostname)
            ->getQuery()
            ->getResult();

        foreach ($judgings as $judging) {
            $this->giveBackJudging($judging->getJudgingid(), $judgehost);
        }

        return array_map(function (Judging $judging) {
            return [
                'jobid' => $judging->getJudgingid(),
                'submitid' => $judging->getSubmission()->getSubmitid(),
            ];
        }, $judgings);
    }

    /**
     * Update the configuration of the given judgehost
     * @Rest\Put("/{hostname}")
     * @IsGranted("ROLE_JUDGEHOST")
     * @OA\Response(
     *     response="200",
     *     description="The modified judgehost",
     *     @OA\JsonContent(type="array", @OA\Items(ref=@Model(type=Judgehost::class)))
     * )
     * @OA\Parameter(
     *     name="hostname",
     *     in="path",
     *     description="The hostname of the judgehost to update",
     *     @OA\Schema(type="string")
     * )
     * @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *         mediaType="application/x-www-form-urlencoded",
     *         @OA\Schema(
     *             @OA\Property(
     *                 property="active",
     *                 description="The new active state of the judgehost",
     *                 type="boolean"
     *             )
     *         )
     *     )
     * )
     */
    public function updateJudgeHostAction(Request $request, string $hostname) : array
    {
        if (!$request->request->has('active')) {
            throw new BadRequestHttpException('Argument \'active\' is mandatory');
        }

        $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);
        if ($judgehost) {
            $judgehost->setActive($request->request->getBoolean('active'));
            $this->em->flush();
        }

        return [$judgehost];
    }

    /**
     * Update the given judging for the given judgehost
     * @Rest\Put("/update-judging/{hostname}/{judgetaskid}")
     * @IsGranted("ROLE_JUDGEHOST")
     * @OA\Response(
     *     response="200",
     *     description="When the judging has been updated"
     * )
     * @OA\Parameter(
     *     name="hostname",
     *     in="path",
     *     description="The hostname of the judgehost that wants to update the judging",
     *     @OA\Schema(type="string")
     * )
     * @OA\Parameter(
     *     name="judgetaskid",
     *     in="path",
     *     description="The ID of the judgetask to update",
     *     @OA\Schema(type="integer")
     * )
     * @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *         mediaType="application/x-www-form-urlencoded",
     *         @OA\Schema(
     *             @OA\Property(
     *                 property="compile_success",
     *                 description="Whether compilation was successful",
     *                 type="boolean"
     *             ),
     *             @OA\Property(
     *                 property="output_compile",
     *                 description="The compile output",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="entry_point",
     *                 description="The determined entrypoint",
     *                 type="string"
     *             )
     *         )
     *     )
     * )
     * @throws NonUniqueResultException
     */
    public function updateJudgingAction(Request $request, string $hostname, int $judgetaskid) : void
    {
        /** @var Judgehost $judgehost */
        $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);
        if (!$judgehost) {
            throw new BadRequestHttpException("Who are you and why are you sending us any data?");
        }

        /** @var JudgingRun $judgingRun */
        $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(['judgetaskid' => $judgetaskid]);
        $query = $this->em->createQueryBuilder()
            ->from(Judging::class, 'j')
            ->join('j.submission', 's')
            ->join('s.contest', 'c')
            ->join('s.team', 't')
            ->join('s.problem', 'p')
            ->select('j, s, c, t, p')
            ->andWhere('j.judgingid = :judgingid')
            ->setParameter(':judgingid', $judgingRun->getJudgingId())
            ->setMaxResults(1)
            ->getQuery();

        /** @var Judging $judging */
        $judging = $query->getOneOrNullResult();
        if (!$judging) {
            throw new BadRequestHttpException("We don't know this judging with judgetaskid ID $judgetaskid.");
        }

        if ($request->request->has('output_compile')) {
            if ($request->request->has('entry_point')) {
                $this->em->transactional(function () use ($query, $request, &$judging) {
                    $submission = $judging->getSubmission();
                    $submission->setEntryPoint($request->request->get('entry_point'));
                    $this->em->flush();
                    $submissionId = $submission->getSubmitid();
                    $contestId    = $submission->getContest()->getCid();
                    $this->eventLogService->log('submission', $submissionId,
                                                EventLogService::ACTION_UPDATE, $contestId);

                    // As EventLogService::log() will clear the entity manager, so the judging has
                    // now become detached. We will have to reload it
                    /** @var Judging $judging */
                    $judging = $query->getOneOrNullResult();
                });
            }

            // Reload judgehost just in case it got cleared above.
            /** @var Judgehost $judgehost */
            $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);

            $output_compile = base64_decode($request->request->get('output_compile'));
            if ($request->request->getBoolean('compile_success')) {
                if ($judging->getOutputCompile() === null) {
                    $judging
                        ->setOutputCompile($output_compile)
                        ->setJudgehost($judgehost);
                    $this->em->flush();

                    $this->eventLogService->log('judging', $judging->getJudgingid(),
                        EventLogService::ACTION_CREATE, $judging->getContest()->getCid());
                } elseif ($judging->getResult() === Judging::RESULT_COMPILER_ERROR) {
                    // The new result contradicts a former one, that's not good.
                    // Since the other judgehost was not successful, but we were , assume that the other judgehost
                    // is broken and disable it.
                    $disabled = [
                        'kind' => 'judgehost',
                        'hostname' => $judging->getJudgehost()->getHostname(),
                    ];
                    $error = new InternalError();
                    $error
                        ->setJudging($judging)
                        ->setContest($judging->getContest())
                        ->setDescription('Compilation results are different for j' . $judging->getJudgingid())
                        ->setJudgehostlog('New compilation output: ' . $output_compile)
                        ->setTime(Utils::now())
                        ->setDisabled($disabled);
                    $this->em->persist($error);
                }
            } else {
                $this->em->transactional(function () use (
                    $request,
                    $judgehost,
                    $judging,
                    $query,
                    $output_compile
                ) {
                    if ($judging->getOutputCompile() === null) {
                        $judging
                            ->setOutputCompile($output_compile)
                            ->setResult(Judging::RESULT_COMPILER_ERROR)
                            ->setJudgehost($judgehost)
                            ->setEndtime(Utils::now());
                        $this->em->flush();

                        $this->eventLogService->log('judging', $judging->getJudgingid(),
                            EventLogService::ACTION_CREATE, $judging->getContest()->getCid());

                        // As EventLogService::log() will clear the entity manager, so the judging has
                        // now become detached. We will have to reload it
                        /** @var Judging $judging */
                        $judging = $query->getOneOrNullResult();

                        // Invalidate judgetasks.
                        $this->em->getConnection()->executeUpdate(
                            'UPDATE judgetask SET valid=0'
                            . ' WHERE jobid=:jobid',
                            [
                                ':jobid' => $judging->getJudgingid(),
                            ]
                        );
                        $this->em->flush();
                    } else if ($judging->getResult() !== Judging::RESULT_COMPILER_ERROR) {
                        // The new result contradicts a former one, that's not good.
                        // Since the other judgehost was successful, but we were not, assume that the current judgehost
                        // is broken and disable it.
                        $disabled = [
                            'kind' => 'judgehost',
                            'hostname' => $judgehost->getHostname(),
                        ];
                        $error = new InternalError();
                        $error
                            ->setJudging($judging)
                            ->setContest($judging->getContest())
                            ->setDescription('Compilation results are different for j' . $judging->getJudgingid())
                            ->setJudgehostlog('New compilation output: ' . $output_compile)
                            ->setTime(Utils::now())
                            ->setDisabled($disabled);
                        $this->em->persist($error);
                    }

                    $judgingId = $judging->getJudgingid();
                    $contestId = $judging->getSubmission()->getContest()->getCid();
                    $this->dj->auditlog('judging', $judgingId, 'judged',
                                        'compiler-error', $judgehost->getHostname(), $contestId);

                    $this->maybeUpdateActiveJudging($judging);
                    $this->em->flush();
                    if (!$this->config->get('verification_required') &&
                        $judging->getValid()) {
                        $this->eventLogService->log('judging', $judgingId,
                                                    EventLogService::ACTION_UPDATE, $contestId);
                    }

                    $submission = $judging->getSubmission();
                    $contest    = $submission->getContest();
                    $team       = $submission->getTeam();
                    $problem    = $submission->getProblem();
                    $this->scoreboardService->calculateScoreRow($contest, $team, $problem);

                    $message = sprintf("submission %i, judging %i: compiler-error",
                                       $submission->getSubmitid(), $judging->getJudgingid());
                    $this->dj->alert('reject', $message);
                });
            }
        }

        $judgehost->setPolltime(Utils::now());
        $this->em->flush();
    }

    /**
     * Add back debug info.
     * @Rest\Post("/add-debug-info/{hostname}/{judgeTaskId}")
     * @IsGranted("ROLE_JUDGEHOST")
     * @OA\Response(
     *     response="200",
     *     description="When the debug info has been added"
     * )
     * @OA\Parameter(
     *     name="hostname",
     *     in="path",
     *     description="The hostname of the judgehost that wants to add the debug info",
     *     @OA\Schema(type="string")
     * )
     * @OA\Parameter(
     *     name="judgeTaskId",
     *     in="path",
     *     description="The ID of the judgetask to add",
     *     @OA\Schema(type="string")
     * )
     */
    public function addDebugInfo(
        Request $request,
        string $hostname,
        int $judgeTaskId
    ): void {
        $required = [
            'output_run',
        ];

        foreach ($required as $argument) {
            if (!$request->request->has($argument)) {
                throw new BadRequestHttpException(
                    sprintf("Argument '%s' is mandatory", $argument));
            }
        }

        /** @var Judgehost $judgehost */
        $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);
        if (!$judgehost) {
            throw new BadRequestHttpException("Who are you and why are you sending us any data?");
        }

        /** @var JudgeTask $judgeTask */
        $judgeTask = $this->em->getRepository(JudgeTask::class)->find($judgeTaskId);
        if ($judgeTask === null) {
            throw new BadRequestHttpException(
                'Inconsistent data, no judgetask known with judgetaskid = ' . $judgeTaskId . '.');
        }

        /** @var JudgingRun $judgingRun */
        $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(
            [
                'judging' => $judgeTask->getJobId(),
                'testcase' => $judgeTask->getTestcaseId(),
            ]
        );
        if ($judgingRun === null) {
            throw new BadRequestHttpException(
                'Inconsistent data, no judging run known with jid = ' . $judgeTask->getJobId() . '.');
        }

        $outputRun = base64_decode($request->request->get('output_run'));

        /** @var JudgingRunOutput $judgingRunOutput */
        $judgingRunOutput = $judgingRun->getOutput();
        $judgingRunOutput->setOutputRun($outputRun);
        $this->em->flush();
    }

    /**
     * Add one JudgingRun. When relevant, finalize the judging.
     * @Rest\Post("/add-judging-run/{hostname}/{judgeTaskId}")
     * @IsGranted("ROLE_JUDGEHOST")
     * @OA\Response(
     *     response="200",
     *     description="When the judging run has been added"
     * )
     * @OA\Parameter(
     *     name="hostname",
     *     in="path",
     *     description="The hostname of the judgehost that wants to add the judging run",
     *     @OA\Schema(type="string")
     * )
     * @OA\Parameter(
     *     name="judgeTaskId",
     *     in="path",
     *     description="The ID of the judgetask to add",
     *     @OA\Schema(type="string")
     * )
     * @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *         mediaType="application/x-www-form-urlencoded",
     *         @OA\Schema(
     *             required={"runresult","runtime","output_run","output_diff","output_error","output_system"},
     *             @OA\Property(
     *                 property="runresult",
     *                 description="The result of the run",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="runtime",
     *                 description="The runtime of the run",
     *                 type="number",
     *                 format="float"
     *             ),
     *             @OA\Property(
     *                 property="output_run",
     *                 description="The (base64-encoded) output of the run",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="output_diff",
     *                 description="The (base64-encoded) output diff of the run",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="output_error",
     *                 description="The (base64-encoded) error output of the run",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="output_system",
     *                 description="The (base64-encoded) system output of the run",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="metadata",
     *                 description="The (base64-encoded) metadata",
     *                 type="string"
     *             )
     *         )
     *     )
     * )
     * @throws DBALException
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws ORMException
     */
    public function addJudgingRunAction(
        Request $request,
        string $hostname,
        int $judgeTaskId
    ) : void {
        $required = [
            'runresult',
            'runtime',
            'output_run',
            'output_diff',
            'output_error',
            'output_system'
        ];

        foreach ($required as $argument) {
            if (!$request->request->has($argument)) {
                throw new BadRequestHttpException(
                    sprintf("Argument '%s' is mandatory", $argument));
            }
        }

        $runResult    = $request->request->get('runresult');
        $runTime      = $request->request->get('runtime');
        $outputRun    = $request->request->get('output_run');
        $outputDiff   = $request->request->get('output_diff');
        $outputError  = $request->request->get('output_error');
        $outputSystem = $request->request->get('output_system');
        $metadata     = $request->request->get('metadata');

        /** @var Judgehost $judgehost */
        $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);
        if (!$judgehost) {
            throw new BadRequestHttpException("Who are you and why are you sending us any data?");
        }

        $this->addSingleJudgingRun($judgeTaskId, $hostname, $runResult, $runTime,
                                   $outputSystem, $outputError, $outputDiff, $outputRun, $metadata);
        $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);
        $judgehost->setPolltime(Utils::now());
        $this->em->flush();
    }

    /**
     * Internal error reporting (back from judgehost)
     *
     * @Rest\Post("/internal-error")
     * @IsGranted("ROLE_JUDGEHOST")
     * @OA\Response(
     *     response="200",
     *     description="The ID of the created internal error",
     *     @OA\JsonContent(type="integer")
     * )
     * @OA\RequestBody(
     *     required=true,
     *     @OA\MediaType(
     *         mediaType="application/x-www-form-urlencoded",
     *         @OA\Schema(
     *             required={"description","judgehostlog","disabled"},
     *             @OA\Property(
     *                 property="description",
     *                 description="The description of the internal error",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="judgehostlog",
     *                 description="The log of the judgehost",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="disabled",
     *                 description="The object to disable in JSON format",
     *                 type="string"
     *             ),
     *             @OA\Property(
     *                 property="judgetaskid",
     *                 description="The ID of the judgeTask that was being worked on",
     *                 type="integer"
     *             )
     *         )
     *     )
     * )
     * @throws NonUniqueResultException
     * @throws ORMException
     */
    public function internalErrorAction(Request $request): ?int
    {
        $required = ['description', 'judgehostlog', 'disabled'];
        foreach ($required as $argument) {
            if (!$request->request->has($argument)) {
                throw new BadRequestHttpException(sprintf("Argument '%s' is mandatory", $argument));
            }
        }
        $description  = $request->request->get('description');
        $judgehostlog = $request->request->get('judgehostlog');
        $disabled     = $request->request->get('disabled');

        // The judgetaskid is allowed to be NULL.
        $judgeTaskId = $request->request->get('judgetaskid');
        $judging = NULL;
        $cid = NULL;
        if ($judgeTaskId) {
            /** @var JudgeTask $judgeTask */
            $judgeTask = $this->em->getRepository(JudgeTask::class)->findOneBy(['judgetaskid' => $judgeTaskId]);
            if ($judgeTask->getType() == JudgeTaskType::JUDGING_RUN) {
                $judgingId = $judgeTask->getJobId();
                /** @var Judging $judging */
                $judging = $this->em->getRepository(Judging::class)->findOneBy(['judgingid' => $judgingId]);
                $cid = $judging->getContest()->getCid();
            }
        }

        $disabled = $this->dj->jsonDecode($disabled);
        if (in_array($disabled['kind'], array('compile_script', 'compare_script', 'run_script'))) {
            $field_name = $disabled['kind'] . '_id';
            // Disable any outstanding judgetasks with the same script that have not been claimed yet.
            $this->em->getConnection()->executeUpdate(
                'UPDATE judgetask SET valid=0'
                . ' WHERE ' . $field_name . ' = :id'
                . ' AND hostname IS NULL',
                [
                    ':id' => $disabled[$field_name],
                ]
            );

            // Since these are the immutable executables, we need to map it to the mutable one first to make linking and
            // re-enabling possible.
            /** @var Executable $executable */
            $executable = $this->em->getRepository(Executable::class)
                ->findOneBy(['immutableExecutable' => $disabled[$field_name]]);
            if (!$executable) {
                // Race condition where the user changed the executable (hopefully for the better). Ignore.
                return null;
            }
            $disabled['execid'] = $executable->getExecid();
            unset($disabled[$field_name]);
            $disabled['kind'] = 'executable';
        }

        // Group together duplicate internal errors.
        // Note that it may be good to be able to ignore fields here, e.g. judgingid with compile errors.
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(InternalError::class, 'e')
            ->select('e')
            ->andWhere('e.description = :description')
            ->andWhere('e.disabled = :disabled')
            ->andWhere('e.status = :status')
            ->setParameter(':description', $description)
            ->setParameter(':disabled', $this->dj->jsonEncode($disabled))
            ->setParameter(':status', 'open')
            ->setMaxResults(1);

        /** @var InternalError $error */
        $error = $queryBuilder->getQuery()->getOneOrNullResult();

        if ($error) {
            // FIXME: in some cases it makes sense to extend the known information, e.g. the judgehostlog.
            return $error->getErrorid();
        }

        /** @var Contest|null $contest */
        $contest = null;
        if ($cid) {
            $contest = $this->em->getRepository(Contest::class)->find($cid);
        }

        $error = new InternalError();
        $error
            ->setJudging($judging)
            ->setContest($contest)
            ->setDescription($description)
            ->setJudgehostlog($judgehostlog)
            ->setTime(Utils::now())
            ->setDisabled($disabled);

        $this->em->persist($error);
        $this->em->flush();

        $this->dj->setInternalError($disabled, $contest, false);

        if (in_array($disabled['kind'], ['problem', 'language', 'judgehost', 'executable']) && $judgingId) {
            // Give back judging if we have to.
            if ($disabled['kind'] == 'judgehost') {
                $hostname = $request->request->get('hostname');
                $judgehost = $this->em->getRepository(Judgehost::class)->findOneBy(['hostname' => $hostname]);
            } else {
                $judgehost = null;
            }
            $this->giveBackJudging((int)$judgingId, $judgehost);
        }

        return $error->getErrorid();
    }

    /**
     * Give back the unjudged runs from the judging with the given judging ID
     * @param int       $judgingId
     * @param Judgehost|null $judgehost If set, only partially returns judgetasks instead of full judging.
     */
    protected function giveBackJudging(int $judgingId, ?Judgehost $judgehost): void
    {
        /** @var Judging $judging */
        $judging = $this->em->getRepository(Judging::class)->find($judgingId);
        if ($judging) {
            $this->em->transactional(function () use ($judging, $judgehost) {
                /** @var JudgingRun $run */
                foreach ($judging->getRuns() as $run) {
                    if ($judgehost === null) {
                        // This is coming from internal errors, reset the whole judging.
                        $run->getJudgetask()
                            ->setValid(false);
                        continue;
                    }

                    // We do not have to touch any finished runs
                    if ($run->getRunresult() !== null) {
                        continue;
                    }

                    // For the other runs, we need to reset the judge task if it belongs to the current judgehost
                    if ($run->getJudgetask()->getHostname() === $judgehost->getHostname()) {
                        $run->getJudgetask()
                            ->setHostname(null)
                            ->setStarttime(null);
                    }
                }

                $this->em->flush();
            });

            if ($judgehost === null) {
                // Invalidate old judging and create a new one - but without judgetasks yet since this was triggered by
                // an internal error.
                $judging->setValid(false);
                $newJudging = new Judging();
                $newJudging
                    ->setContest($judging->getContest())
                    ->setValid(true)
                    ->setSubmission($judging->getSubmission())
                    ->setOriginalJudging($judging);
                $this->em->persist($newJudging);
                $this->em->flush();
            }

            $this->dj->auditlog('judging', $judgingId, 'given back'
                . ($judgehost === null ? '' : ' for judgehost ' . $judgehost->getHostname()), null,
                $judgehost === null ? null : $judgehost->getHostname(), $judging->getContest()->getCid());
        }
    }

    /**
     * Add a single judging to a given judging run
     * @throws DBALException
     * @throws NoResultException
     * @throws NonUniqueResultException
     * @throws ORMException
     */
    private function addSingleJudgingRun(
        int $judgeTaskId,
        string $hostname,
        string $runResult,
        string $runTime,
        string $outputSystem,
        string $outputError,
        string $outputDiff,
        string $outputRun,
        string $metadata
    ) {
        $resultsRemap = $this->config->get('results_remap');
        $resultsPrio  = $this->config->get('results_prio');

        if (array_key_exists($runResult, $resultsRemap)) {
            $this->logger->info('JudgeTask %d remapping result %s -> %s',
                                [ $judgeTaskId, $runResult, $resultsRemap[$runResult] ]);
            $runResult = $resultsRemap[$runResult];
        }

        $this->em->transactional(function () use (
            $judgeTaskId,
            $runTime,
            $runResult,
            $outputSystem,
            $outputError,
            $outputDiff,
            $outputRun,
            $metadata
        ) {
            /** @var JudgingRun $judgingRun */
            $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(
                ['judgetaskid' => $judgeTaskId]);
            if ($judgingRun === null) {
                throw new BadRequestHttpException(
                    'Inconsistent data, no judging run known with judgetaskid = ' . $judgeTaskId . '.');
            }
            $judgingRunOutput = new JudgingRunOutput();
            $judgingRun->setOutput($judgingRunOutput);
            $judgingRun
                ->setRunresult($runResult)
                ->setRuntime((float)$runTime)
                ->setEndtime(Utils::now());
            $judgingRunOutput
                ->setOutputRun(base64_decode($outputRun))
                ->setOutputDiff(base64_decode($outputDiff))
                ->setOutputError(base64_decode($outputError))
                ->setOutputSystem(base64_decode($outputSystem))
                ->setMetadata(base64_decode($metadata));

            $judging = $judgingRun->getJudging();
            $this->maybeUpdateActiveJudging($judging);
            $this->em->flush();

            if ($judging->getValid()) {
                $this->eventLogService->log('judging_run', $judgingRun->getRunid(),
                                            EventLogService::ACTION_CREATE, $judging->getContest()->getCid());
            }
        });

        // Reload the judging, as EventLogService::log will clear the entity manager.
        // For the judging, also load in the submission and some of it's relations.
        /** @var JudgingRun $judgingRun */
        $judgingRun = $this->em->getRepository(JudgingRun::class)->findOneBy(['judgetaskid' => $judgeTaskId]);
        $judging = $judgingRun->getJudging();

        // result of this judging_run has been stored. now check whether
        // we're done or if more testcases need to be judged.

        /** @var JudgingRun[] $runs */
        $runs = $this->em->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->leftJoin(JudgingRun::class, 'jr', Join::WITH, 'jt.testcase_id = jr.testcase AND jr.judging = :judgingid')
            ->select('jr.runresult')
            ->andWhere('jt.jobid = :judgingid')
            ->andWhere('jr.judging = :judgingid')
            ->andWhere('jt.testcase_id = jr.testcase')
            ->orderBy('jt.judgetaskid')
            ->setParameter(':judgingid', $judging->getJudgingid())
            ->getQuery()
            ->getArrayResult();
        $runresults = array_column($runs, 'runresult');

        $oldResult = $judging->getResult();

        if (($result = SubmissionService::getFinalResult($runresults, $resultsPrio)) !== null) {
            // Lookup global lazy evaluation of results setting and possible problem specific override.
            $lazyEval    = $this->config->get('lazy_eval_results');
            $problemLazy = $judging->getSubmission()->getContestProblem()->getLazyEvalResults();
            if (isset($problemLazy)) {
                $lazyEval = $problemLazy;
            }

            $judging->setResult($result);

            $hasNullResults = false;
            foreach ($runresults as $runresult) {
                if ($runresult === NULL) {
                    $hasNullResults = true;
                    break;
                }
            }
            if (!$hasNullResults || $lazyEval) {
                // NOTE: setting endtime here determines in testcases_GET
                // whether a next testcase will be handed out.
                $judging->setEndtime(Utils::now());
                $this->maybeUpdateActiveJudging($judging);
            }
            $this->em->flush();

            // Only update if the current result is different from what we had before.
            // This should only happen when the old result was NULL.
            if ($oldResult !== $result) {
                if ($oldResult !== null) {
                    throw new \BadMethodCallException('internal bug: the evaluated result changed during judging');
                }

                if ($lazyEval) {
                    // We don't want to continue on this problem, even if there's spare resources.
                    $this->em->getConnection()->executeUpdate(
                        'UPDATE judgetask SET valid=0, priority=:priority'
                        . ' WHERE jobid=:jobid'
                        . ' AND hostname IS NULL',
                        [
                            ':priority' => JudgeTask::PRIORITY_LOW,
                            ':jobid' => $judgingRun->getJudgingid(),
                        ]
                    );
                } else {
                    // Decrease priority of remaining unassigned judging runs.
                    $this->em->getConnection()->executeUpdate(
                        'UPDATE judgetask SET priority=:priority'
                        . ' WHERE jobid=:jobid'
                        . ' AND hostname IS NULL',
                        [
                            ':priority' => JudgeTask::PRIORITY_LOW,
                            ':jobid' => $judgingRun->getJudgingid(),
                        ]
                    );
                }

                /** @var Submission $submission */
                $submission = $judging->getSubmission();
                $contest    = $submission->getContest();
                $team       = $submission->getTeam();
                $problem    = $submission->getProblem();
                $this->scoreboardService->calculateScoreRow($contest, $team, $problem);

                // We call alert here before possible validation. Note that
                // this means that these alert messages should be treated as
                // confidential information.
                $msg = sprintf("submission %s, judging %s: %s",
                               $submission->getSubmitid(), $judging->getJudgingid(), $result);
                $this->dj->alert($result === 'correct' ? 'accept' : 'reject', $msg);

                // Potentially send a balloon, i.e. if no verification required (case of verification required is
                // handled in jury/SubmissionController::verifyAction).
                if (!$this->config->get('verification_required') && $judging->getValid()) {
                    $this->balloonService->updateBalloons($contest, $submission, $judging);
                }

                $this->dj->auditlog('judging', $judging->getJudgingid(), 'judged', $result, $hostname);
            }

            // Send an event for an endtime (and max runtime update).
            if ($judging->getValid()) {
                $this->eventLogService->log('judging', $judging->getJudgingid(),
                    EventLogService::ACTION_UPDATE, $judging->getContest()->getCid());
            }
        }
    }

    private function maybeUpdateActiveJudging(Judging $judging): void
    {
        if ($judging->getRejudging() !== null) {
            $rejudging = $judging->getRejudging();
            if ($rejudging->getAutoApply()) {
                $judging->getSubmission()->setRejudging(null);
                foreach ($judging->getSubmission()->getJudgings() as $j) {
                    $j->setValid(false);
                }
                $judging->setValid(true);

                // Check whether we are completely done with this rejudging.
                if ($rejudging->getEndtime() === null && $this->rejudgingService->calculateTodo($rejudging)['todo'] == 0) {
                    $rejudging->setEndtime(Utils::now());
                    $rejudging->setFinishUser(null);
                    $this->em->flush();
                }
            }

            if ($rejudging->getRepeat() > 1 && $rejudging->getEndtime() === null
                    && $this->rejudgingService->calculateTodo($rejudging)['todo'] == 0) {
                $numberOfRepetitions = $this->em->createQueryBuilder()
                    ->from(Rejudging::class, 'r')
                    ->select('COUNT(r.rejudgingid) AS cnt')
                    ->andWhere('r.repeatedRejudging = :repeat_rejudgingid')
                    ->setParameter('repeat_rejudgingid', $rejudging->getRepeatedRejudging()->getRejudgingid())
                    ->getQuery()
                    ->getSingleScalarResult();
                // Only "cancel" the rejudging if it's not the last.
                if ($numberOfRepetitions < $rejudging->getRepeat()) {
                    $rejudging
                        ->setEndtime(Utils::now())
                        ->setFinishUser(null)
                        ->setValid(false);
                    $this->em->flush();

                    // Reset association before creating the new rejudging.
                    $this->em->getConnection()->executeUpdate(
                        'UPDATE submission
                            SET rejudgingid = NULL
                            WHERE rejudgingid = :rejudgingid',
                        [':rejudgingid' => $rejudging->getRejudgingid()]);
                    $this->em->flush();

                    $skipped = [];
                    /** @var array[] $judgings */
                    $judgings = $this->em->createQueryBuilder()
                        ->from(Judging::class, 'j')
                        ->leftJoin('j.submission', 's')
                        ->leftJoin('s.rejudging', 'r')
                        ->leftJoin('s.team', 't')
                        ->select('j', 's', 'r', 't')
                        ->andWhere('j.rejudging = :rejudgingid')
                        ->setParameter('rejudgingid', $rejudging->getRejudgingid())
                        ->getQuery()
                        ->setHint(Query::HINT_REFRESH, TRUE)
                        ->getResult();
                    $this->rejudgingService->createRejudging($rejudging->getReason(), $judgings,
                        false, $rejudging->getRepeat(), $rejudging->getRepeatedRejudging(), $skipped);
                }
            }
        }
    }

    private function getSubmissionsToJudge(Judgehost $judgehost, $restrictJudgingOnSameJudgehost)
    {
        // Get all active contests
        $contests   = $this->dj->getCurrentContests();
        $contestIds = array_map(function (Contest $contest) {
            return $contest->getCid();
        }, $contests);

        // If there are no active contests, there is nothing to do
        if (empty($contestIds)) {
            return [];
        }

        // Determine all viable submissions
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(Submission::class, 's')
            ->join('s.team', 't')
            ->join('s.language', 'l')
            ->join('s.contest_problem', 'cp')
            ->select('s')
            ->andWhere('s.judgehost IS NULL')
            ->andWhere('s.contest IN (:contestIds)')
            ->setParameter(':contestIds', $contestIds)
            ->andWhere('l.allowJudge= 1')
            ->andWhere('cp.allowJudge = 1')
            ->andWhere('s.valid = 1')
            ->orderBy('t.judging_last_started', 'ASC')
            ->addOrderBy('s.submittime', 'ASC')
            ->addOrderBy('s.submitid', 'ASC');

        // Apply restrictions
        if ($judgehost->getRestriction()) {
            $restrictions = $judgehost->getRestriction()->getRestrictions();

            if (isset($restrictions['contest'])) {
                $queryBuilder
                    ->andWhere('s.contest IN (:restrictionContestIds)')
                    ->setParameter(':restrictionContestIds', $restrictions['contest']);
            }

            if (isset($restrictions['problem'])) {
                $queryBuilder
                    ->andWhere('s.problem IN (:restrictionProblemIds)')
                    ->setParameter(':restrictionProblemIds', $restrictions['problem']);
            }

            if (isset($restrictions['language'])) {
                $queryBuilder
                    ->andWhere('s.language IN (:restrictionLanguageIds)')
                    ->setParameter(':restrictionLanguageIds', $restrictions['language']);
            }
        }
        if ($restrictJudgingOnSameJudgehost) {
            $queryBuilder
                ->leftJoin('s.judgings', 'j', Join::WITH, 'j.judgehost = :judgehost')
                ->andWhere('j.judgehost IS NULL')
                ->setParameter(':judgehost', $judgehost->getHostname());
        }

        /** @var Submission[] $submissions */
        $submissions = $queryBuilder->getQuery()->getResult();
        return $submissions;
    }

    /**
     * Get files for a given type and id.
     * @Rest\Get("/get_files/{type}/{id}")
     * @Security("is_granted('ROLE_JURY') or is_granted('ROLE_JUDGEHOST')")
     * @throws NonUniqueResultException
     * @OA\Response(
     *     response="200",
     *     description="The files for the submission, testcase or script.",
     *     @OA\Schema(ref="#/definitions/SourceCodeList")
     * )
     * @OA\Parameter(
     *     name="type",
     *     in="path",
     *     description="The type to",
     *     @OA\Schema(type="string")
     * )
     * @OA\Parameter(ref="#/components/parameters/id")
     */
    public function getFilesAction(string $type, string $id) : array
    {
        switch($type) {
            case 'source':
                return $this->getSourceFiles($id);
            case 'testcase':
                return $this->getTestcaseFiles($id);
            case 'compile':
            case 'run':
            case 'compare':
                return $this->getExecutableFiles($id);
            default:
                throw new BadRequestHttpException('Unknown type requested.');
        }
    }

    private function getSourceFiles(string $id): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(SubmissionFile::class, 'f')
            ->select('f')
            ->andWhere('f.submission = :submitid')
            ->setParameter(':submitid', $id)
            ->orderBy('f.ranknumber');

        /** @var SubmissionFile[] $files */
        $files = $queryBuilder->getQuery()->getResult();

        if (empty($files)) {
            throw new NotFoundHttpException(sprintf('Source code for submission with ID \'%s\' not found', $id));
        }

        $result = [];
        foreach ($files as $file) {
            $result[]   = [
                'filename' => $file->getFilename(),
                'content' => base64_encode($file->getSourcecode()),
            ];
        }
        return $result;
    }

    private function getExecutableFiles(string $id): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(ExecutableFile::class, 'f')
            ->select('f')
            ->andWhere('f.immutableExecutable = :immutable_execid')
            ->setParameter(':immutable_execid', $id)
            ->orderBy('f.rank');

        /** @var ExecutableFile[] $files */
        $files = $queryBuilder->getQuery()->getResult();

        if (empty($files)) {
            throw new NotFoundHttpException(sprintf('Files for immutable executable with ID \'%s\' not found', $id));
        }

        $result = [];
        foreach ($files as $file) {
            $result[]   = [
                'filename' => $file->getFilename(),
                'content' => base64_encode($file->getFileContent()),
                'is_executable' => $file->isExecutable(),
            ];
        }
        return $result;
    }

    private function getTestcaseFiles(string $id): array
    {
        $queryBuilder = $this->em->createQueryBuilder()
            ->from(TestcaseContent::class, 'f')
            ->select('f.input, f.output')
            ->andWhere('f.testcase = :testcaseid')
            ->setParameter(':testcaseid', $id);

        /** @var string[] $inout */
        $inout = $queryBuilder->getQuery()->getOneOrNullResult();

        if (empty($inout)) {
            throw new NotFoundHttpException(sprintf('Files for testcase_content with ID \'%s\' not found', $id));
        }

        $result = [];
        foreach (['input', 'output'] as $k) {
            $result[] = [
                'filename' => $k,
                'content' => base64_encode($inout[$k]),
            ];
        }
        return $result;
    }

    /**
     * Fetch work tasks.
     * @Rest\Post("/fetch-work")
     * @Security("is_granted('ROLE_JUDGEHOST')")
     */
    public function getJudgeTasksAction(Request $request): array
    {
        if (!$request->request->has('hostname')) {
            throw new BadRequestHttpException('Argument \'hostname\' is mandatory');
        }
        $hostname = $request->request->get('hostname');

        /** @var Judgehost $judgehost */
        $judgehost = $this->em->getRepository(Judgehost::class)->find($hostname);
        if (!$judgehost) {
            throw new BadRequestHttpException('Register yourself first. You are not known to us yet.');
        }

        // Update last seen of judgehost
        $judgehost->setPolltime(Utils::now());
        $this->em->flush();

        // If this judgehost is not active, there's nothing to do.
        if (!$judgehost->getActive()) {
            return [];
        }

        // TODO: Determine a good max batch size here. We may want to do something more elaborate like looking at
        // previous judgements of the same testcase and use median runtime as an indicator.
        $max_batchsize = 5;
        if ($request->request->has('max_batchsize')) {
            $max_batchsize = $request->request->get('max_batchsize');
        }

        // First try to get any debug info tasks that are assigned to this host.
        /** @var JudgeTask[] $judgetasks */
        $judgetasks = $this->em
            ->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->select('jt')
            ->andWhere('jt.hostname = :hostname')
            ->andWhere('jt.starttime IS NULL')
            ->andWhere('jt.valid = 1')
            ->andWhere('jt.type = :type')
            ->setParameter(':hostname', $hostname)
            ->setParameter(':type', JudgeTaskType::DEBUG_INFO)
            ->addOrderBy('jt.priority')
            ->addOrderBy('jt.judgetaskid')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();
        if (!empty($judgetasks)) {
            return $this->serializeJudgeTasks($judgetasks, $hostname);
        }

        /* Our main objective is to work on high priority work first while keeping the additional overhead of splitting
         * work across judgehosts (e.g. additional compilation) low.
         *
         * We follow the following high-level strategy here to assign work:
         * 1) If there's an unfinished job (e.g. a judging)
         *    - to which we already contributed, and
         *    - where the remaining JudgeTasks have a priority <= 0,
         *    then continue handing out JudgeTasks for this job.
         * 2) Determine highest priority level of outstanding JudgeTasks, so that we work on one of the most important work
         *    items.
         *    a) If there's an already started job to which we already contributed,
         *       then continue working on this job.
         *    b) Otherwise, if there's an unstarted job, hand out tasks from that job.
         *    c) Otherwise, contribute to an already started job even if we didn't contribute yet.

         * Note that there could potentially be races in the selection of work, but adding synchronization mechanisms is
         * more costly than starting a possible only second most important work item.
         */

        // This is case 1) from above: continue what we have started (if still important).
        // TODO: These queries would be much easier and less heavy on the DB with an extra table.
        $started_judgetaskids = array_column(
            $this->em
                ->createQueryBuilder()
                ->from(JudgeTask::class, 'jt')
                ->select('jt.jobid')
                ->andWhere('jt.hostname = :hostname')
                ->setParameter(':hostname', $hostname)
                ->groupBy('jt.jobid')
                ->getQuery()
                ->getArrayResult(),
            'jobid');
        if (!empty($started_judgetaskids)) {
            $queryBuilder = $this->em->createQueryBuilder();
            /** @var JudgeTask[] $judgetasks */
            $judgetasks = $queryBuilder
                ->from(JudgeTask::class, 'jt')
                ->select('jt')
                ->andWhere('jt.hostname IS NULL')
                ->andWhere('jt.valid = 1')
                ->andWhere('jt.priority <= :default_priority')
                ->andWhere($queryBuilder->expr()->In('jt.jobid', $started_judgetaskids))
                ->addOrderBy('jt.priority')
                ->addOrderBy('jt.judgetaskid')
                ->setParameter(':default_priority', JudgeTask::PRIORITY_DEFAULT)
                ->setMaxResults($max_batchsize)
                ->getQuery()
                ->getResult();
            if (!empty($judgetasks)) {
                return $this->serializeJudgeTasks($judgetasks, $hostname);
            }
        }

        // Determine highest priority level of outstanding JudgeTasks.
        $max_priority = $this->em
            ->createQueryBuilder()
            ->from(JudgeTask::class, 'jt')
            ->select('jt.priority')
            ->andWhere('jt.hostname IS NULL')
            ->andWhere('jt.valid = 1')
            ->addOrderBy('jt.priority')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($max_priority === null) {
            return [];
        }
        $max_priority = $max_priority['priority'];

        // This is case 2.a) from above: continue what we have started (if same priority as the current most important
        // judgetask).
        // TODO: We should merge this with the query above to reduce code duplication.
        if ($started_judgetaskids) {
            /** @var JudgeTask[] $judgetasks */
            $judgetasks = $this->em
                ->createQueryBuilder()
                ->from(JudgeTask::class, 'jt')
                ->select('jt')
                ->andWhere('jt.hostname IS NULL')
                ->andWhere('jt.valid = 1')
                ->andWhere('jt.priority = :max_priority')
                ->setParameter(':max_priority', $max_priority)
                ->andWhere($queryBuilder->expr()->In('jt.jobid', $started_judgetaskids))
                ->addOrderBy('jt.judgetaskid')
                ->setMaxResults($max_batchsize)
                ->getQuery()
                ->getResult();
            if (!empty($judgetasks)) {
                return $this->serializeJudgeTasks($judgetasks, $hostname);
            }
        }

        // This is case 2.b) from above: start something new.
        // First, we have to filter for unfinished jobs. This would be easier with a separate table storing the
        // job state.
        $started_judgetaskids = array_column(
            $this->em
                ->createQueryBuilder()
                ->from(JudgeTask::class, 'jt')
                ->select('jt.jobid')
                ->andWhere('jt.hostname IS NOT NULL')
                ->groupBy('jt.jobid')
                ->getQuery()
                ->getArrayResult(),
            'jobid');
        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder
            ->from(JudgeTask::class, 'jt')
            ->join(Submission::class, 's', Join::WITH, 'jt.submitid = s.submitid')
            ->join('s.team', 't')
            ->select('jt')
            ->andWhere('jt.hostname IS NULL')
            ->andWhere('jt.valid = 1')
            ->andWhere('jt.priority = :max_priority')
            ->setParameter(':max_priority', $max_priority)
            ->addOrderBy('t.judging_last_started', 'ASC')
            ->addOrderBy('s.submittime', 'ASC')
            ->addOrderBy('s.submitid', 'ASC');
        if (!empty($started_judgetaskids)) {
            $queryBuilder
            ->andWhere($queryBuilder->expr()->notIn('jt.jobid', $started_judgetaskids));
        }
        /** @var JudgeTask[] $judgetasks */
        $judgetasks =
            $queryBuilder
            ->addOrderBy('jt.judgetaskid')
            ->setMaxResults($max_batchsize)
            ->getQuery()
            ->getResult();
        if (!empty($judgetasks)) {
            return $this->serializeJudgeTasks($judgetasks, $hostname);
        }

        if ($this->config->get('enable_parallel_judging')) {
            // This is case 2.c) from above: contribute to a job someone else has started but we have not contributed yet.
            // We intentionally lift the restriction on priority in this case to get any high priority work.
            /** @var JudgeTask[] $judgetasks */
            $judgetasks = $this->em
                ->createQueryBuilder()
                ->from(JudgeTask::class, 'jt')
                ->select('jt')
                ->andWhere('jt.hostname IS NULL')
                ->andWhere('jt.valid = 1')
                ->addOrderBy('jt.priority')
                ->addOrderBy('jt.judgetaskid')
                ->setMaxResults($max_batchsize)
                ->getQuery()
                ->getResult();
            if (!empty($judgetasks)) {
                return $this->serializeJudgeTasks($judgetasks, $hostname);
            }
        }

        return [];
    }

    /** @param JudgeTask[] $judgeTasks */
    private function serializeJudgeTasks($judgeTasks, string $hostname): array
    {
        if (empty($judgeTasks)) {
            return [];
        }

        // Filter by submit_id.
        $submit_id = $judgeTasks[0]->getSubmitid();
        $judgetaskids = [];
        foreach ($judgeTasks as $judgeTask) {
           if ($judgeTask->getSubmitid() == $submit_id) {
               $judgetaskids[] = $judgeTask->getJudgetaskid();
           }
        }

        $now = Utils::now();
        $numUpdated = $this->em->getConnection()->executeUpdate(
            'UPDATE judgetask SET hostname = :hostname, starttime = :starttime WHERE starttime IS NULL AND valid = 1 AND judgetaskid IN (:ids)',
            [
                ':hostname' => $hostname,
                ':starttime' => $now,
                ':ids' => $judgetaskids,
            ],
            [
                ':ids' => Connection::PARAM_INT_ARRAY,
            ]
        );

        if ($numUpdated == 0) {
            // Bad luck, some other judgehost beat us to it.
            return [];
        }

        // We got at least one, let's update the starttime of the corresponding judging if haven't done so in the past.
        $starttime_set = $this->em->getConnection()->executeUpdate(
            'UPDATE judging SET starttime = :starttime WHERE judgingid = :jobid AND starttime IS NULL',
            [
                ':starttime' => $now,
                ':jobid' => $judgeTasks[0]->getJobId(),
            ]
        );

        if ($starttime_set && $judgeTasks[0]->getType() == JudgeTaskType::JUDGING_RUN) {
            /** @var Submission $submission */
            $submission = $this->em->getRepository(Submission::class)->findOneBy(['submitid' => $submit_id]);
            $teamid = $submission->getTeam()->getTeamid();

            $this->em->getConnection()->executeUpdate(
                'UPDATE team SET judging_last_started = :starttime WHERE teamid = :teamid',
                [
                    ':starttime' => $now,
                    ':teamid' => $teamid,
                ]
            );
        }

        if ($numUpdated == sizeof($judgeTasks)) {
            // We got everything, let's ship it!
            return $judgeTasks;
        }

        // A bit unlucky, we only got partially the assigned work, so query what was assigned to us.
        $queryBuilder = $this->em->createQueryBuilder();
        $partialJudgeTaskIds = array_column(
            $queryBuilder
                ->from(JudgeTask::class, 'jt')
                ->select('jt.judgetaskid')
                ->andWhere('jt.hostname = :hostname')
                ->setParameter(':hostname', $hostname)
                ->andWhere($queryBuilder->expr()->In('jt.judgetaskid', $judgetaskids))
                ->getQuery()
                ->getArrayResult(),
            'judgetaskid');

        $partialJudgeTasks = [];
        foreach ($judgeTasks as $judgeTask) {
            if (in_array($judgeTask->getJudgetaskid(), $partialJudgeTaskIds)) {
                $partialJudgeTasks[] = $judgeTask;
            }
        }
        return $partialJudgeTasks;
    }
}
