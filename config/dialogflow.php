<?php
/**
 * Dialogflow Intent Routing (Free Tier)
 * Maps user intents to StayWise features
 * 
 * This provides NLU routing even on the free Dialogflow tier
 * Uses pattern matching to enhance AI responses
 */

class DialogflowIntentRouter {
    
    // Intent patterns
    private static $intents = [
        'payment' => [
            'patterns' => ['payment', 'rent', 'pay', 'billing', 'invoice', 'amount due', 'how much'],
            'action' => 'payments',
            'response' => 'You can upload your payment proof in the Payments section.',
        ],
        'maintenance' => [
            'patterns' => ['fix', 'repair', 'broken', 'leak', 'maintenance', 'issue', 'problem', 'complaint'],
            'action' => 'complaints',
            'response' => 'Please submit your maintenance request in the Complaints section with a detailed description.',
        ],
        'announcement' => [
            'patterns' => ['announcement', 'news', 'notice', 'update', 'building', 'policy'],
            'action' => 'announcements',
            'response' => 'Check the Announcements section for the latest building updates.',
        ],
        'profile' => [
            'patterns' => ['profile', 'information', 'details', 'my info', 'contact', 'phone', 'address'],
            'action' => 'profile',
            'response' => 'You can update your information in your Profile section.',
        ],
        'help' => [
            'patterns' => ['help', 'how do i', 'guide', 'how to', 'tutorial', 'instructions'],
            'action' => 'faq',
            'response' => 'Check the FAQ section or contact admin for detailed help.',
        ],
    ];
    
    public static function detectIntent(string $message): array {
        $message = strtolower($message);
        $confidence = 0;
        $detected = null;
        
        foreach (self::$intents as $intent => $data) {
            $patterns = $data['patterns'];
            $matches = 0;
            foreach ($patterns as $pattern) {
                if (strpos($message, $pattern) !== false) {
                    $matches++;
                }
            }
            $score = $matches / count($patterns);
            if ($score > $confidence) {
                $confidence = $score;
                $detected = $intent;
            }
        }
        
        return [
            'intent' => $detected,
            'confidence' => $confidence,
            'action' => $detected ? self::$intents[$detected]['action'] : null,
            'action_url' => $detected ? '../' . self::$intents[$detected]['action'] . '.php' : null,
        ];
    }
    
    public static function enhanceResponse(string $aiResponse, string $userMessage): string {
        $intent = self::detectIntent($userMessage);
        if ($intent['confidence'] > 0.5 && $intent['action']) {
            $suggestion = " → Visit the <strong>" . ucfirst($intent['action']) . "</strong> section for more details.";
            return $aiResponse . $suggestion;
        }
        return $aiResponse;
    }
    
    public static function getQuickAction(string $message): ?array {
        $intent = self::detectIntent($message);
        if ($intent['confidence'] > 0.7) {
            return [
                'type' => 'quick_action',
                'action' => $intent['action'],
                'label' => ucfirst($intent['action']),
                'url' => $intent['action_url'],
            ];
        }
        return null;
    }
}
?>
