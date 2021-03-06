<?php

class GithubChangelogGenerator
{
    private $token;
    private $fileName = 'CHANGELOG.md';

    private $currentIssues;

    const LABEL_TYPE_BUG        = 'type_bug';
    const LABEL_TYPE_FEATURE    = 'type_feature';
    const LABEL_TYPE_PR         = 'type_pr';

    /* @var array */
    private $issueLabelMapping = [
        self::LABEL_TYPE_BUG => [
            'bug',
        ],
        self::LABEL_TYPE_FEATURE => [
            'enhancement',
            'feature',
        ],
    ];

    public function __construct($token = null, $issueMapping = null)
    {
        if ($issueMapping) {
            $this->issueLabelMapping = $issueMapping;
        }

        $this->token = $token;
    }

    /**
     * Create a changelog from given username and repository
     *
     * @param $user
     * @param $repository
     * @param null $savePath
     */
    public function createChangelog($user, $repository, $savePath = null)
    {
        $this->currentIssues = null;

        $savePath = !$savePath ? dirname(__FILE__) . '/' . $this->fileName : null;
        $releases = $this->collectReleaseIssues($user, $repository);

        $file = fopen($savePath, 'w');
        fwrite($file, '# Change Log' . "\n\r");
        foreach($releases as $release)
        {
            fwrite($file, sprintf('## [%s](%s) (%s)' . "\r\n\r\n", $release->tag_name, $release->html_url, $release->published_at));
            $this->writeReleaseIssues($file, $release->issues);
        }
    }

    /**
     * Write release issues to file
     *
     * @param $fileStream
     * @param $issues
     */
    private function writeReleaseIssues($fileStream, $issues)
    {
        foreach ($issues as $type => $currentIssues)
        {
            switch ($type)
            {
                case $this::LABEL_TYPE_BUG: fwrite($fileStream, '**Fixed bugs:**' . "\r\n\r\n"); break;
                case $this::LABEL_TYPE_FEATURE: fwrite($fileStream, '**New features:**' . "\r\n\r\n"); break;
                case $this::LABEL_TYPE_PR: fwrite($fileStream, '**Merged pull requests:**' . "\r\n\r\n"); break;
            }

            foreach ($currentIssues as $issue) {
                fwrite($fileStream, sprintf('- %s [\#%s](%s)' . "\r\n", $issue->title, $issue->number, $issue->html_url));
            }

            fwrite($fileStream, "\r\n");
        }
    }

    /**
     * Collect all issues from release tags
     *
     * @param $user
     * @param $repository
     * @param null $startDate
     * @return array
     * @throws Exception
     */
    private function collectReleaseIssues($user, $repository, $startDate = null)
    {
        $releases = $this->callGitHubApi(sprintf('repos/%s/%s/releases', $user, $repository));
        $data = [];

        if (count($releases) <= 0) {
            throw new \Exception('No releases found for this repository');
        }

        do
        {
            $currentRelease = current($releases);

            if ($startDate && date_diff(new \DateTime($currentRelease->published_at), new \DateTime($startDate))->days <= 0) {
                continue;
            }

            $lastRelease = next($releases);
            $lastReleaseDate = $lastRelease ? $lastRelease->published_at : null;
            prev($releases);

            $currentRelease->issues = $this->collectIssues($lastReleaseDate, $user, $repository);
            $data[] = $currentRelease;

        }while(next($releases));

        return $data;
    }

    /**
     * Collect all issues from release date
     *
     * @param $lastReleaseDate
     * @param $user
     * @param $repository
     * @return array
     */
    private function collectIssues($lastReleaseDate, $user, $repository)
    {
        if (!$this->currentIssues) {
            $this->currentIssues = $this->callGitHubApi(sprintf('repos/%s/%s/issues', $user, $repository), [
                'state' => 'closed'
            ]);
        }

        $issues = [];
        foreach ($this->currentIssues as $x => $issue)
        {
            if (new \DateTime($issue->closed_at) > new \DateTime($lastReleaseDate) || $lastReleaseDate == null)
            {
                unset($this->currentIssues[$x]);

                $type = $this->getTypeFromLabels($issue->labels);
                if (!$type && isset($issue->pull_request)) {
                    $type = $this::LABEL_TYPE_PR;
                }

                if ($type) {
                    $events = $this->callGitHubApi(sprintf('repos/%s/%s/issues/%s/events', $user, $repository, $issue->number));
                    $isMerged = false;

                    foreach ($events as $event) {
                        if(($event->event == 'merged' || $event->event == 'referenced') && !empty($event->commit_id)) {
                            $isMerged = true;
                            break;
                        }
                    }

                    if ($isMerged) {
                        $issues[$type][] = $issue;
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Get the Issue Type from Issue Labels
     *
     * @param $labels
     * @return bool|int|null|string
     */
    private function getTypeFromLabels($labels)
    {
        $type = null;
        foreach ($labels as $label)
        {
            if($foundLabel = $this->getTypeFromLabel($label->name)) {
                return $foundLabel;
            }
        }

        return null;
    }

    /**
     * Get Type by label
     *
     * @param $label
     * @param null $haystack
     * @return bool|int|string
     */
    private function getTypeFromLabel($label, $haystack = null)
    {
        $haystack = !$haystack ? $this->issueLabelMapping : $haystack;
        foreach($haystack as $key => $value) {
            $current_key = $key;
            if((is_array($value) && $this->getTypeFromLabel($label, $value) !== false) || (!is_array($value) && strcasecmp($label, $value) === 0)) {
                return $current_key;
            }
        }
        return false;
    }

    /**
     * API call to the github api v3
     *
     * @param $call
     * @param array $params
     * @param int $page
     * @return mixed|null
     */
    private function callGitHubApi($call, $params = [], $page = 1)
    {
        $params = array_merge(
            $params,
            [
                'access_token' => $this->token,
                'page' => $page
            ]
        );

        $options  = [
            'http' => [
                'user_agent' => 'github-changelog-generator'
            ]
        ];

        $url = sprintf('https://api.github.com/%s?%s', $call, http_build_query($params));
        $context  = stream_context_create($options);
        $response = file_get_contents($url, null, $context);
        $response = $response ? json_decode($response) : [];

        if(count(preg_grep('#Link: <(.+?)>; rel="next"#', $http_response_header)) === 1) {
            return array_merge($response, $this->callGitHubApi($call, $params, ++$page));
        }

        return $response;
    }
}
