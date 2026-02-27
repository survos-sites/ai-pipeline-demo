<?php
declare(strict_types=1);

namespace App\Task;

use Survos\AiPipelineBundle\Task\AbstractVisionTask;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment as TwigEnvironment;

/**
 * Example custom task — not in the bundle.
 *
 * Estimates the approximate collector/auction value of an item based on
 * its description, classification, and visible condition.
 *
 * Prompt template: templates/bundles/SurvosAiPipelineBundle/prompt/estimate_value/
 * (Note: this task uses its own template directory, not an override.)
 *
 * To add to a pipeline entry in images.json:
 *   "pipeline": ["ocr_mistral", "classify", "basic_description", "estimate_value"]
 */
final class EstimateValueTask extends AbstractVisionTask
{
    public function __construct(
        #[Autowire(service: 'ai.agent.metadata')]
        AgentInterface $agent,
        TwigEnvironment $twig,
    ) {
        parent::__construct($agent, $twig);
    }

    public function getTask(): string
    {
        return 'estimate_value';
    }

    /**
     * Custom template path — lives in the app's own templates directory,
     * not in the bundle's resources.  Demonstrates how to add a task with
     * prompt templates that live entirely in the consuming app.
     */
    public function run(array $inputs, array $priorResults = [], array $context = []): array
    {
        // Override the template path for this task to use app templates
        // rather than @SurvosAiPipeline/... (which the base class uses).
        $tplContext = $this->promptContext($inputs, $priorResults, $context);
        $taskSlug   = $this->getTask();

        $systemPrompt = trim($this->twig->render("ai/prompt/{$taskSlug}/system.html.twig", $tplContext));
        $userPrompt   = trim($this->twig->render("ai/prompt/{$taskSlug}/user.html.twig",   $tplContext));

        $imageUrl    = $inputs['image_url'] ?? null;
        $userMessage = $imageUrl !== null
            ? \Symfony\AI\Platform\Message\Message::ofUser($userPrompt, new \Symfony\AI\Platform\Message\Content\ImageUrl($imageUrl))
            : \Symfony\AI\Platform\Message\Message::ofUser($userPrompt);

        $messages = new \Symfony\AI\Platform\Message\MessageBag(
            \Symfony\AI\Platform\Message\Message::forSystem($systemPrompt),
            $userMessage,
        );

        $result  = $this->agent->call($messages);
        $content = $result->getContent();

        return is_array($content) ? $content : ['raw' => (string) $content];
    }
}
