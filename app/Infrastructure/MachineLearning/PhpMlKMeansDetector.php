<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineLearning;

use App\Domain\Anomaly\AnomalyDetector;
use App\Domain\Anomaly\ClusteringMetrics;
use App\Domain\Anomaly\DetectionResult;
use App\Domain\Anomaly\FeatureVector;
use App\Domain\Anomaly\KMeansParameters;
use Phpml\Clustering\KMeans;
use RuntimeException;

/**
 * PHP-ML K-Means behind the domain port. K-Means assigns every sample to a
 * centroid — there is no noise — so the density bridge to this domain is:
 * a member of a cluster smaller than minimumSamples is an anomaly (null
 * cluster), mirroring DBSCAN's "too sparse to be a profile" intuition.
 *
 * PHP-ML seeds centroids with k-means++ using unseedable random_int, so a
 * single run can land in a local optimum. The detector therefore runs
 * RESTARTS passes and keeps the lowest-inertia one (sklearn's n_init
 * strategy); sample indices are preserved via point labels.
 */
final readonly class PhpMlKMeansDetector implements AnomalyDetector
{
    private const RESTARTS = 10;

    public function __construct(private readonly KMeansParameters $parameters)
    {
    }

    public function detect(array $vectors): DetectionResult
    {
        if ($vectors === []) {
            throw new RuntimeException('K-Means requires at least one feature vector');
        }

        $samples = array_map(static fn (FeatureVector $vector) => $vector->values(), $vectors);

        return $this->bestOfRestarts($vectors, $samples);
    }

    /**
     * @param list<FeatureVector> $vectors
     * @param list<list<float>> $samples
     */
    private function bestOfRestarts(array $vectors, array $samples): DetectionResult
    {
        $best = null;
        $bestInertia = INF;

        for ($restart = 0; $restart < self::RESTARTS; $restart++) {
            $result = $this->runOnce($samples);
            $inertia = ClusteringMetrics::inertia($vectors, $result);

            if ($inertia < $bestInertia) {
                $bestInertia = $inertia;
                $best = $result;
            }
        }

        return $best ?? new DetectionResult(array_fill(0, count($samples), null));
    }

    /**
     * @param list<list<float>> $samples
     */
    private function runOnce(array $samples): DetectionResult
    {
        $clusterer = new KMeans($this->parameters->clusters);

        /** @var array<int, array<int|string, list<float>>> $clusters clusterId => index => sample */
        $clusters = $clusterer->cluster($samples);

        return new DetectionResult($this->assignments($samples, $clusters));
    }

    /**
     * @param list<list<float>> $samples
     * @param array<int, array<int|string, list<float>>> $clusters
     *
     * @return list<int|null>
     */
    private function assignments(array $samples, array $clusters): array
    {
        $sparseClusters = $this->sparseClusterIds($clusters);

        $assignments = array_fill(0, count($samples), null);

        foreach ($clusters as $clusterId => $members) {
            if (in_array($clusterId, $sparseClusters, true)) {
                continue;
            }

            foreach ($members as $index => $sample) {
                $assignments[(int) $index] = (int) $clusterId;
            }
        }

        return $assignments;
    }

    /**
     * @param array<int, array<int|string, list<float>>> $clusters
     *
     * @return list<int>
     */
    private function sparseClusterIds(array $clusters): array
    {
        $sparse = [];

        foreach ($clusters as $clusterId => $members) {
            if (count($members) < $this->parameters->minimumSamples) {
                $sparse[] = (int) $clusterId;
            }
        }

        return $sparse;
    }
}
