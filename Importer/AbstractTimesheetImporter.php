<?php

/*
 * This file is part of the "Import bundle" for Kimai.
 * All rights reserved by Kevin Papst (www.kevinpapst.de).
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\ImportBundle\Importer;

use App\Activity\ActivityService;
use App\Customer\CustomerService;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\User;
use App\Project\ProjectService;
use App\Repository\TagRepository;
use App\Timesheet\TimesheetService;
use App\User\UserService;
use App\Utils\Duration;
use App\Validator\ValidationFailedException;
use KimaiPlugin\ImportBundle\Model\ImportData;
use KimaiPlugin\ImportBundle\Model\ImportModelInterface;
use KimaiPlugin\ImportBundle\Model\ImportRow;
use KimaiPlugin\ImportBundle\Model\TimesheetImportModel;

abstract class AbstractTimesheetImporter
{
    /**
     * @var Customer[]
     */
    private array $customerCache = [];
    /**
     * @var Project[]
     */
    private array $projectCache = [];
    /**
     * @var Activity[]
     */
    private array $activityCache = [];
    /**
     * @var array<User|null>
     */
    private array $userCache = [];
    /**
     * @var Tag[]
     */
    private array $tagCache = [];

    // some statistics to display to the user
    private int $createdUsers = 0;
    private int $createdProjects = 0;
    private int $createdCustomers = 0;
    private int $createdActivities = 0;
    private int $createdTags = 0;
    private bool $globalActivity = true;

    public function __construct(
        private CustomerService $customerService,
        private ProjectService $projectService,
        private ActivityService $activityService,
        private UserService $userService,
        private TagRepository $tagRepository,
        private TimesheetService $timesheetRepository
    ) {
    }

    public function importRow(Duration $durationParser, ImportData $data, ImportRow $row, bool $dryRun): void
    {
        try {
            $record = $row->getData();

            if (!\array_key_exists('Duration', $record)) {
                $record['Duration'] = 0;
            }
            if (!\array_key_exists('Tags', $record)) {
                $record['Tags'] = '';
            }
            if (!\array_key_exists('Exported', $record)) {
                $record['Exported'] = false;
            }
            if (!\array_key_exists('Rate', $record)) {
                $record['Rate'] = null;
            }
            if (!\array_key_exists('HourlyRate', $record)) {
                $record['HourlyRate'] = null;
            }
            if (!\array_key_exists('InternalRate', $record)) {
                $record['InternalRate'] = null;
            }
            if (!\array_key_exists('FixedRate', $record)) {
                $record['FixedRate'] = null;
            }
            if (!\array_key_exists('Billable', $record)) {
                $record['Billable'] = true;
            }
            if (!empty($record['From'])) {
                $len = \strlen($record['From']);
                if ($len === 1) {
                    $record['From'] = '0' . $record['From'] . ':00';
                } elseif ($len == 2) {
                    $record['From'] = $record['From'] . ':00';
                }
            }
            if (!empty($record['To'])) {
                $len = \strlen($record['To']);
                if ($len === 1) {
                    $record['To'] = '0' . $record['To'] . ':00';
                } elseif ($len == 2) {
                    $record['To'] = $record['To'] . ':00';
                }
            }

            $this->validateRow($record);

            $user = $record['User'];
            $email = \array_key_exists('Email', $record) ? $record['Email'] : $user;
            if (null === ($user = $this->getUser($user, $email, $dryRun))) {
                throw new ImportException(
                    sprintf('Unknown user %s', $record['User'])
                );
            }

            $project = $this->getProject($record['Project'], $record['Customer'], $dryRun);
            $activity = $this->getActivity($record['Activity'], $project, $dryRun);

            $begin = null;
            $end = null;
            $duration = 0;

            if (!empty($record['Duration'])) {
                if (\is_int($record['Duration'])) {
                    $duration = $record['Duration'];
                } else {
                    $duration = $durationParser->parseDurationString($record['Duration']);
                }
            }

            $timezone = new \DateTimeZone($user->getTimezone());

            if (\array_key_exists('Begin', $record) && \array_key_exists('End', $record)) {
                try {
                    $begin = new \DateTime($record['Begin'], $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
                try {
                    $end = new \DateTime($record['End'], $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
            } elseif (empty($record['From']) && empty($record['To'])) {
                try {
                    $begin = new \DateTime($record['Date'] . ' 12:00:00', $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
                try {
                    $end = (new \DateTime())->setTimezone($timezone)->setTimestamp($begin->getTimestamp() + $duration);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
            } elseif (empty($record['From'])) {
                try {
                    $end = new \DateTime($record['Date'] . ' ' . $record['To'], $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
                try {
                    $begin = (new \DateTime())->setTimezone($timezone)->setTimestamp($end->getTimestamp() - $duration);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
            } elseif (empty($record['To'])) {
                try {
                    $begin = new \DateTime($record['Date'] . ' ' . $record['From'], $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
                try {
                    $end = (new \DateTime())->setTimezone($timezone)->setTimestamp($begin->getTimestamp() + $duration);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
            } else {
                try {
                    $begin = new \DateTime($record['Date'] . ' ' . $record['From'], $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }
                try {
                    $end = new \DateTime($record['Date'] . ' ' . $record['To'], $timezone);
                } catch (\Exception $exception) {
                    throw new ImportException($exception->getMessage());
                }

                // fix dates, which are running over midnight
                if ($end < $begin) {
                    if ($duration > 0) {
                        $end = (new \DateTime())->setTimezone($timezone)->setTimestamp($begin->getTimestamp() + $duration);
                    } else {
                        $end->add(new \DateInterval('P1D'));
                    }
                }
            }

            $timesheet = $this->timesheetRepository->createNewTimesheet($user);
            $this->timesheetRepository->prepareNewTimesheet($timesheet);

            if (\is_bool($record['Billable'])) {
                $timesheet->setBillable($record['Billable']);
            }
            $timesheet->setActivity($activity);
            $timesheet->setProject($project);
            $timesheet->setBegin($begin);
            $timesheet->setEnd($end);
            $timesheet->setDescription($record['Description']);
            $timesheet->setExported((bool) $record['Exported']);

            if (!empty($record['Tags'])) {
                foreach (explode(',', $record['Tags']) as $tagName) {
                    if (empty($tagName)) {
                        continue;
                    }

                    $timesheet->addTag($this->getTag($tagName, $dryRun));
                }
            }

            if (!empty($record['Rate'])) {
                $timesheet->setRate((float) $record['Rate']);
            }
            if (!empty($record['HourlyRate'])) {
                $timesheet->setHourlyRate((float) $record['HourlyRate']);
            }
            if (!empty($record['FixedRate'])) {
                $timesheet->setFixedRate((float) $record['FixedRate']);
            }
            if (!empty($record['InternalRate'])) {
                $timesheet->setInternalRate((float) $record['InternalRate']);
            }

            if (!$dryRun) {
                $this->timesheetRepository->saveNewTimesheet($timesheet);
            }
        } catch (ImportException $exception) {
            $row->addError($exception->getMessage());
        } catch (ValidationFailedException $exception) {
            for ($i = 0; $i < $exception->getViolations()->count(); $i++) {
                $row->addError($exception->getViolations()->get($i)->getMessage());
            }
        }
        $data->addRow($row);
    }

    abstract protected function createImportData(ImportRow $row): ImportData;

    /**
     * @param TimesheetImportModel $model
     * @param array<ImportRow> $rows
     * @return ImportData
     */
    public function import(ImportModelInterface $model, array $rows): ImportData
    {
        if (!$model instanceof TimesheetImportModel) {
            throw new ImportException('Invalid import model given, expected TimesheetImportModel');
        }

        $dryRun = $model->isPreview();
        $data = $this->createImportData($rows[0]);

        $durationParser = new Duration();
        $this->globalActivity = $model->isGlobalActivities();

        foreach ($rows as $row) {
            $this->importRow($durationParser, $data, $row, $dryRun);
        }

        if ($this->createdCustomers > 0) {
            $data->addStatus(sprintf('created %s customers', $this->createdCustomers));
        }
        if ($this->createdProjects > 0) {
            $data->addStatus(sprintf('created %s projects', $this->createdProjects));
        }
        if ($this->createdActivities > 0) {
            $data->addStatus(sprintf('created %s activities', $this->createdActivities));
        }
        if ($this->createdTags > 0) {
            $data->addStatus(sprintf('created %s tags', $this->createdTags));
        }
        if ($this->createdUsers > 0) {
            $data->addStatus(sprintf('created %s users', $this->createdUsers));
        }

        return $data;
    }

    private function getUser(string $user, string $email, bool $dryRun): ?User
    {
        if (!\array_key_exists($user, $this->userCache)) {
            $tmpUser = $this->userService->findUserByEmail($email);
            if ($tmpUser === null) {
                $tmpUser = $this->userService->findUserByName($user);
            }

            if ($tmpUser === null) {
                $tmpUser = new User();
                $tmpUser->setEmail($email);
                $tmpUser->setUserIdentifier($user);
                $tmpUser->setPlainPassword(uniqid());
                if (!$dryRun) {
                    $this->userService->saveNewUser($tmpUser);
                }
                $this->createdUsers++;
            }

            $this->userCache[$user] = $tmpUser;
        }

        return $this->userCache[$user];
    }

    private function getTag(string $tagName, bool $dryRun): Tag
    {
        if (!\array_key_exists($tagName, $this->tagCache)) {
            $tag = $this->tagRepository->findTagByName($tagName);

            if ($tag === null) {
                $tag = new Tag();
                $tag->setName($tagName);
                if (!$dryRun) {
                    $this->tagRepository->saveTag($tag);
                }
                $this->createdTags++;
            }

            $this->tagCache[$tagName] = $tag;
        }

        return $this->tagCache[$tagName];
    }

    private function getActivity(string $activity, Project $project, bool $dryRun): Activity
    {
        $cacheKey = $activity;
        if (!$this->globalActivity) {
            $cacheKey = $cacheKey . '_____' . $project->getId();
        } else {
            $cacheKey = $cacheKey . '_____GLOBAL_____';
        }

        if (!\array_key_exists($cacheKey, $this->activityCache)) {
            if (!$this->globalActivity) {
                $tmpActivity = $this->activityService->findActivityByName($activity, $project);
            } else {
                $tmpActivity = $this->activityService->findActivityByName($activity, null);
            }

            if (null === $tmpActivity) {
                $newProject = !$this->globalActivity ? $project : null;
                $tmpActivity = $this->activityService->createNewActivity($newProject);
                $tmpActivity->setName($activity);
                if (!$dryRun) {
                    $this->activityService->saveNewActivity($tmpActivity);
                }
                $this->createdActivities++;
            }

            $this->activityCache[$cacheKey] = $tmpActivity;
        }

        return $this->activityCache[$cacheKey];
    }

    private function getProject(string $project, string $customer, bool $dryRun): Project
    {
        $cacheKey = $project . '_____' . $customer;

        if (!\array_key_exists($cacheKey, $this->projectCache)) {
            $tmpCustomer = $this->getCustomer($customer, $dryRun);
            $tmpProject = $this->projectService->findProjectByName($project);

            if (null !== $tmpProject && $tmpProject->getCustomer() !== null) {
                if ($tmpProject->getCustomer()->getName() !== null && $tmpCustomer->getName() !== null && strcasecmp($tmpProject->getCustomer()->getName(), $tmpCustomer->getName()) !== 0) {
                    $tmpProject = null;
                }
            }

            if ($tmpProject === null) {
                $tmpProject = $this->projectService->createNewProject($tmpCustomer);
                $tmpProject->setName($project);
                if (!$dryRun) {
                    $this->projectService->saveNewProject($tmpProject);
                }
                $this->createdProjects++;
            }

            $this->projectCache[$cacheKey] = $tmpProject;
        }

        return $this->projectCache[$cacheKey];
    }

    private function getCustomer(string $customer, bool $dryRun): Customer
    {
        if (!\array_key_exists($customer, $this->customerCache)) {
            $tmpCustomer = $this->customerService->findCustomerByName($customer);

            if ($tmpCustomer === null) {
                $tmpCustomer = $this->customerService->createNewCustomer($customer);
                if (!$dryRun) {
                    $this->customerService->saveNewCustomer($tmpCustomer);
                }
                $this->createdCustomers++;
            }

            $this->customerCache[$customer] = $tmpCustomer;
        }

        return $this->customerCache[$customer];
    }

    private function validateRow(array $row): void
    {
        $fields = [];

        if (!\array_key_exists('User', $row) || empty($row['User'])) {
            $fields[] = 'User';
        }

        if (empty($row['Project'])) {
            $fields[] = 'Project';
        }

        // negative durations are not supported ...
        if (\is_string($row['Duration']) && $row['Duration'][0] === '-') {
            $fields[] = 'Duration';
        } elseif (\is_int($row['Duration']) && $row['Duration'] < 0) {
            $fields[] = 'Duration';
        }

        if (empty($row['Activity'])) {
            $fields[] = 'Activity';
        }

        if (empty($row['Date']) && empty($row['Begin'])) {
            $fields[] = 'Date';
        }

        if ((empty($row['From']) || empty($row['To']) || empty($row['End'])) && empty($row['Duration'])) {
            $fields[] = 'Duration';
        }

        if (!empty($fields)) {
            throw new ImportException('Empty or missing fields: ' . implode(', ', $fields));
        }
    }
}
