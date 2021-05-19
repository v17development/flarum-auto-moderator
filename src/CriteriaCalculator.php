<?php

/*
 * This file is part of askvortsov/flarum-auto-moderator
 *
 *  Copyright (c) 2021 Alexander Skvortsov.
 *
 *  For detailed copyright and license information, please view the
 *  LICENSE file that was distributed with this source code.
 */

namespace Askvortsov\AutoModerator;

use Askvortsov\AutoModerator\Action\ActionManager;
use Askvortsov\AutoModerator\Metric\MetricDriverInterface;
use Askvortsov\AutoModerator\Metric\MetricManager;
use Askvortsov\AutoModerator\Requirement\RequirementDriverInterface;
use Askvortsov\AutoModerator\Requirement\RequirementManager;
use Flarum\User\Event\LoggedIn;
use Flarum\User\User;
use Illuminate\Support\Arr;

class CriteriaCalculator
{
    /**
     * @var ActionManager
     */
    protected $actions;

    /**
     * @var MetricManager
     */
    protected $metrics;

    /**
     * @var RequirementManager
     */
    protected $requirements;

    public function __construct(ActionManager $actions, MetricManager $metrics, RequirementManager $requirements)
    {
        $this->actions = $actions;
        $this->metrics = $metrics;
        $this->requirements = $requirements;
    }

    protected $prevRuns = [];

    public function recalculate(User $user, string $eventClass)
    {
        $metrics = $this->calcMetrics($user);
        $requirements = $this->calcRequirements($user);

        $prevCriteria = CriteriaCalculator::toAssoc($user->criteria->all());
        $currCriteria = CriteriaCalculator::toAssoc($this->getCriteriaForStats($metrics, $requirements));

        $lostCriteria = array_diff_key($prevCriteria, $currCriteria);
        $gainedCriteria = array_diff_key($currCriteria, $prevCriteria);

        $relevantCriteria = CriteriaCalculator::toAssoc($this->whereTriggeredBy($this->allCriteria(), $eventClass));
        $relevantLostCriteria = array_intersect_key($lostCriteria, $relevantCriteria);
        $relevantGainedCriteria = array_intersect_key($gainedCriteria, $relevantCriteria);

        foreach ($relevantLostCriteria as $criterion) {
            $this->runActions($user, $criterion, false);
        }
        foreach ($relevantGainedCriteria as $criterion) {
            $this->runActions($user, $criterion, true);
        }

        $actualNewCriteria = array_merge(array_diff($prevCriteria, $relevantLostCriteria), $relevantGainedCriteria);
        $user->criteria()->sync(Arr::pluck($actualNewCriteria, 'id'));
    }

    protected function calcMetrics(User $user)
    {
        $metrics = [];

        foreach ($this->metrics->getDrivers() as $name => $driver) {
            $metrics[$name] = $driver->getValue($user);
        }

        return $metrics;
    }

    protected function calcRequirements(User $user)
    {
        $requirements = [];

        foreach ($this->requirements->getDrivers() as $name => $driver) {
            $requirements[$name] = $driver->userSatisfies($user);
        }

        return $requirements;
    }

    protected function getCriteriaForStats(array $metrics, array $requirements)
    {
        return $this->allCriteria()
            ->filter(function (Criterion $criterion) use ($metrics) {
                foreach ($criterion->metrics as $metric) {
                    $userVal = Arr::get($metrics, $metric['type']);
                    
                    // Happens when criteria invalid due to missing ext dependencies
                    if ($userVal === null) return false;
                    $min = Arr::get($metric, "min",  -1);
                    $max = Arr::get($metric, "max",  -1);
                    $withinRange = ($min === -1 || $userVal >= $min) && ($max === -1 || $userVal <= $max);

                    if (!$withinRange) {
                        return false;
                    }
                }

                return true;
            })
            ->filter(function (Criterion $criterion) use ($requirements) {
                foreach ($criterion->requirements as $requirement) {
                    $userSatisfies = Arr::get($requirements, $requirement['type']);

                    // Happens when criteria invalid due to missing ext dependencies
                    if ($userSatisfies === null) return false;
                    $negated = Arr::get($requirement, "negated",  false);
                    $qualifies = ($userSatisfies xor $negated);

                    if (!$qualifies) {
                        return false;
                    }
                }

                return true;
            })
            ->all();
    }

    protected function whereTriggeredBy($criteria, $eventClass)
    {
        $validCriteria = $criteria
            ->filter(function (Criterion $criterion) {
                return $criterion->isValid($this->actions, $this->metrics, $this->requirements);
            });

        if ($eventClass === LoggedIn::class) {
            return $validCriteria;
        }

        $triggeredMetrics = array_filter($this->metrics->getDrivers(), function (MetricDriverInterface $metric) use ($eventClass) {
            return array_key_exists($eventClass, $metric->eventTriggers());
        });

        $triggeredRequirements = array_filter($this->requirements->getDrivers(), function (RequirementDriverInterface $requirement) use ($eventClass) {
            return array_key_exists($eventClass, $requirement->eventTriggers());
        });

        return $validCriteria
            ->filter(function (Criterion $criterion) use ($triggeredMetrics, $triggeredRequirements) {
                $hasTriggeredMetric = collect($criterion->metrics)->contains(function ($metric) use ($triggeredMetrics) {
                    return array_key_exists($metric['type'], $triggeredMetrics);
                });

                $hasTriggeredRequirement = collect($criterion->requirements)->contains(function ($requirement) use ($triggeredRequirements) {
                    return array_key_exists($requirement['type'], $triggeredRequirements);
                });

                return $hasTriggeredMetric || $hasTriggeredRequirement;
            });
            
    }

    protected $criteria;
    
    protected function allCriteria()
    {
        if (!$this->criteria) {
            $this->criteria = Criterion::all();
        }

        return $this->criteria;
    }

    protected function runActions(User $user, Criterion $criterion, bool $gain)
    {
        $drivers = $this->actions->getDrivers();

        collect($criterion->actions)
            ->filter(function ($action) use ($gain) {
                return $action['gain'] === $gain;
            })
            ->each(function ($action) use ($user, $drivers) {
                $drivers[$action['type']]->execute($user, $action['settings']);
            });
    }

    /**
     * Converts model query results into an associative array with the ID as the key.
     */
    protected static function toAssoc($arr)
    {
        $newArr = [];

        foreach ($arr as $model) {
            $newArr[$model['id']] = $model;
        }

        return $newArr;
    }
}
