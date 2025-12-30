<?php

namespace App\Http\Controllers;

use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Services\OpenAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AiChatController extends Controller
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Get all AI chats for a user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            
            $chats = AiChat::where('user_id', $userId)
                ->where('is_active', true)
                ->orderBy('updated_at', 'desc')
                ->get()
                ->map(function($chat) {
                    return [
                        'id' => $chat->id,
                        'title' => $chat->title ?: 'New Chat',
                        'created_at' => $chat->created_at,
                        'updated_at' => $chat->updated_at,
                        'message_count' => $chat->messages()->count()
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $chats,
                'count' => $chats->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve chats',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific chat with messages
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $chat = AiChat::with('messages')
                ->where('user_id', $userId)
                ->findOrFail($id);
            
            $messages = $chat->messages()
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function($message) {
                    return [
                        'id' => $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'created_at' => $message->created_at
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'chat' => [
                        'id' => $chat->id,
                        'title' => $chat->title ?: 'New Chat',
                        'created_at' => $chat->created_at,
                        'updated_at' => $chat->updated_at
                    ],
                    'messages' => $messages
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve chat',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start a new chat
     */
    public function store(Request $request)
    {
        try {
            $userId = $request->user()->id;
            
            $chat = AiChat::create([
                'user_id' => $userId,
                'session_id' => Str::uuid(),
                'title' => 'New Chat',
                'is_active' => true
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'id' => $chat->id,
                    'title' => $chat->title,
                    'session_id' => $chat->session_id,
                    'created_at' => $chat->created_at
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create chat',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a message to AI and get response
     */
    public function sendMessage(Request $request, $chatId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'message' => 'required|string|max:1000'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user()->id;
            $chat = AiChat::where('user_id', $userId)->findOrFail($chatId);
            $message = $request->input('message');

            // Save user message
            $userMessage = AiChatMessage::create([
                'ai_chat_id' => $chat->id,
                'role' => 'user',
                'content' => $message
            ]);

            // Get chat history for context
            $chatHistory = $chat->messages()
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function($msg) {
                    return [
                        'role' => $msg->role,
                        'content' => $msg->content
                    ];
                })
                ->toArray();

            // Get user context for personalized responses
            $userContext = $this->getUserContext($request->user());
            
            // Send to OpenAI with user context
            $aiResponse = $this->openAIService->sendMessage($message, $chatHistory, $userContext);

            // Save AI response
            $aiMessage = AiChatMessage::create([
                'ai_chat_id' => $chat->id,
                'role' => 'assistant',
                'content' => $aiResponse
            ]);

            // Update chat title if it's the first message
            if ($chat->title === 'New Chat' && $chat->messages()->count() <= 2) {
                $chat->update(['title' => Str::limit($message, 30)]);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user_message' => [
                        'id' => $userMessage->id,
                        'role' => 'user',
                        'content' => $message,
                        'created_at' => $userMessage->created_at
                    ],
                    'ai_message' => [
                        'id' => $aiMessage->id,
                        'role' => 'assistant',
                        'content' => $aiResponse['message'],
                        'created_at' => $aiMessage->created_at
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a chat
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $chat = AiChat::where('user_id', $userId)->findOrFail($id);
            $chat->update(['is_active' => false]);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Chat deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete chat',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user context for personalized AI responses
     */
    private function getUserContext($user): array
    {
        if (!$user) {
            return [];
        }

        $context = [
            'name' => $user->name,
            'age' => $user->age,
            'gender' => $user->gender,
            'weight' => $user->weight,
            'weight_unit' => $user->weight_unit,
            'height' => $user->height,
            'height_unit' => $user->height_unit,
            'goal' => $user->goal,
            'activity_level' => $user->activity_level,
            'daily_calorie_goal' => $user->daily_calorie_goal,
            'daily_steps_goal' => $user->daily_steps_goal,
            'daily_water_goal' => $user->daily_water_goal,
        ];

        // Load user preferences if they exist
        if ($user->userPreferences) {
            $preferences = $user->userPreferences;
            $context = array_merge($context, [
                'dietary_preferences' => $preferences->dietary_preferences,
                'allergies' => $preferences->allergies,
                'meal_types' => $preferences->meal_types,
                'caloric_goal' => $preferences->caloric_goal,
                'cooking_time_preference' => $preferences->cooking_time_preference,
                'serving_preference' => $preferences->serving_preference,
            ]);
        }

        // Load user goals if they exist
        if ($user->userGoal) {
            $context['user_goals'] = [
                'steps' => $user->userGoal->steps,
                'calories' => $user->userGoal->calories,
                'water' => $user->userGoal->water,
            ];
        }

        // Remove empty values
        return array_filter($context, function($value) {
            return !is_null($value) && $value !== '';
        });
    }
}