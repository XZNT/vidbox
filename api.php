<?php
header('Content-Type: application/json');

// Configuration
$dbFile = 'database.json';
$uploadDir = 'uploads/';
$maxFileSize = 500 * 1024 * 1024; // 500MB
$allowedTypes = ['video/mp4', 'video/webm', 'video/ogg'];

// Initialize or load database
function loadDatabase() {
    global $dbFile;
    if (!file_exists($dbFile)) {
        $initialDB = [
            'videos' => [],
            'users' => [],
            'stats' => [],
            'comments' => []
        ];
        file_put_contents($dbFile, json_encode($initialDB, JSON_PRETTY_PRINT));
        return $initialDB;
    }
    return json_decode(file_get_contents($dbFile), true);
}

// Save database
function saveDatabase($data) {
    global $dbFile;
    return file_put_contents($dbFile, json_encode($data, JSON_PRETTY_PRINT));
}

try {
    $db = loadDatabase();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    // Get request data
    $input = null;
    if ($method !== 'GET') {
        $input = json_decode(file_get_contents('php://input'), true);
    }

    $response = ['success' => true];

    switch ($action) {
        case 'get_comments':
            $videoId = $_GET['videoId'] ?? null;
            if ($videoId) {
                $response['comments'] = $db['comments'][$videoId] ?? [];
            } else {
                $response['comments'] = $db['comments'];
            }
            break;

        case 'update_comments':
            if (!isset($input['videoId']) || !isset($input['username']) || !isset($input['comment'])) {
                throw new Exception('Missing required fields');
            }

            $videoId = $input['videoId'];
            $comment = [
                'id' => uniqid(),
                'username' => $input['username'],
                'text' => $input['comment'],
                'timestamp' => date('c'),
                'replies' => []
            ];

            if (!isset($db['comments'][$videoId])) {
                $db['comments'][$videoId] = [];
            }

            if (isset($input['parentCommentId'])) {
                $found = false;
                foreach ($db['comments'][$videoId] as &$parentComment) {
                    if ($parentComment['id'] === $input['parentCommentId']) {
                        $parentComment['replies'][] = $comment;
                        $found = true;
                        break;
                    }
                }
                if (!$found) throw new Exception('Parent comment not found');
            } else {
                array_unshift($db['comments'][$videoId], $comment);
            }

            // Update stats
            if (!isset($db['stats'][$videoId])) {
                $db['stats'][$videoId] = ['views' => 0, 'likes' => [], 'totalComments' => 0];
            }
            $db['stats'][$videoId]['totalComments'] = count($db['comments'][$videoId]);

            saveDatabase($db);
            $response['comment'] = $comment;
            break;

        case 'update_likes':
            if (!isset($input['videoId']) || !isset($input['username'])) {
                throw new Exception('Missing required fields');
            }

            $videoId = $input['videoId'];
            if (!isset($db['stats'][$videoId])) {
                $db['stats'][$videoId] = ['views' => 0, 'likes' => [], 'totalComments' => 0];
            }

            $isLiked = in_array($input['username'], $db['stats'][$videoId]['likes']);
            if ($input['action'] === 'like' && !$isLiked) {
                $db['stats'][$videoId]['likes'][] = $input['username'];
            } elseif ($input['action'] === 'unlike' && $isLiked) {
                $db['stats'][$videoId]['likes'] = array_values(
                    array_diff($db['stats'][$videoId]['likes'], [$input['username']])
                );
            }

            saveDatabase($db);
            break;
			
			case 'get_videos':
    if (!isset($db['videos'])) {
        $db['videos'] = [];
    }
    $response['videos'] = $db['videos'];
    $response['success'] = true;
    break;

        case 'update_views':
            if (!isset($input['videoId']) || !isset($input['username'])) {
                throw new Exception('Missing required fields');
            }

            $videoId = $input['videoId'];
            $viewKey = $videoId . '_' . $input['username'];

            if (!isset($db['stats'][$videoId])) {
                $db['stats'][$videoId] = ['views' => 0, 'likes' => [], 'totalComments' => 0, 'viewedBy' => []];
            }

            if (!in_array($viewKey, $db['stats'][$videoId]['viewedBy'])) {
                $db['stats'][$videoId]['viewedBy'][] = $viewKey;
                $db['stats'][$videoId]['views'] = count($db['stats'][$videoId]['viewedBy']);
                saveDatabase($db);
            }

            $response['stats'] = [
                'views' => $db['stats'][$videoId]['views'],
                'likes' => count($db['stats'][$videoId]['likes']),
                'totalComments' => $db['stats'][$videoId]['totalComments'] ?? 0
            ];
            break;
			
			case 'get_stats':
    if (!isset($db['stats'])) {
        $db['stats'] = [];
    }
    $response['stats'] = $db['stats'];
    break;
	
	case 'search_stats':
    $userId = $_GET['userId'] ?? null;
    if (!$userId) {
        throw new Exception('User ID required');
    }
    
    if (!isset($db['userSearches'])) {
        $db['userSearches'] = [];
    }
    
    // Get user's searches
    $userSearches = $db['userSearches'][$userId] ?? [];
    
    // Get global trending
    $allSearches = [];
    foreach ($db['userSearches'] as $searches) {
        foreach ($searches as $query => $stats) {
            if (!isset($allSearches[$query])) {
                $allSearches[$query] = [
                    'query' => $query,
                    'count' => 0
                ];
            }
            $allSearches[$query]['count'] += $stats['count'];
        }
    }
    
    // Sort and get top 3 trending
    uasort($allSearches, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    $response['userSearches'] = $userSearches;
    $response['trending'] = array_values(array_slice($allSearches, 0, 3, true));
    break;

case 'search':
    $query = $_GET['query'] ?? '';
    $userId = $_GET['userId'] ?? '';
    
    if (empty($query) || empty($userId)) {
        throw new Exception('Search query and user ID are required');
    }

    // Initialize user searches if not exists
    if (!isset($db['userSearches'])) {
        $db['userSearches'] = [];
    }
    if (!isset($db['userSearches'][$userId])) {
        $db['userSearches'][$userId] = [];
    }

    // Update search stats for this user
    $searchKey = strtolower(trim($query));
    if (!isset($db['userSearches'][$userId][$searchKey])) {
        $db['userSearches'][$userId][$searchKey] = [
            'query' => $query,
            'count' => 0,
            'lastSearched' => null
        ];
    }
    $db['userSearches'][$userId][$searchKey]['count']++;
    $db['userSearches'][$userId][$searchKey]['lastSearched'] = date('c');

    // Search in videos
    $results = [];
    foreach ($db['videos'] as $video) {
        if (
            stripos($video['title'], $query) !== false ||
            stripos($video['description'], $query) !== false ||
            (isset($video['tags']) && array_filter($video['tags'], function($tag) use ($query) {
                return stripos($tag, $query) !== false;
            }))
        ) {
            // Add stats for the video
            $videoId = $video['id'];
            $video['stats'] = $db['stats'][$videoId] ?? [
                'views' => 0,
                'likes' => [],
                'totalComments' => 0
            ];
            $results[] = $video;
        }
    }

    saveDatabase($db);
    
    $response['results'] = $results;
    break;

        case 'update_user':
            if (!isset($input['userId']) || !isset($input['username'])) {
                throw new Exception('Missing required fields');
            }

            $userId = $input['userId'];
            if (isset($db['users'][$userId])) {
                $db['users'][$userId] = array_merge($db['users'][$userId], $input);
            } else {
                $db['users'][$userId] = [
                    'userId' => $userId,
                    'username' => $input['username'],
                    'likedVideos' => [],
                    'viewedVideos' => [],
                    'createdAt' => date('c'),
                    'lastActive' => date('c')
                ];
            }

            saveDatabase($db);
            $response['user'] = $db['users'][$userId];
            break;

        case 'upload':
    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error occurred');
    }

    $file = $_FILES['video'];
    
    // Validate file size and type
    if ($file['size'] > $maxFileSize) {
        throw new Exception('File is too large');
    }

    // Create uploads directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Generate unique filename
    $filename = uniqid('video_') . '.mp4';
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save file');
    }

    // Create video entry
    $newVideo = [
        'id' => uniqid(),
        'url' => $filepath,
        'title' => $_POST['title'] ?? 'Untitled',
        'description' => $_POST['description'] ?? '',
        'tags' => !empty($_POST['tags']) ? explode(',', $_POST['tags']) : [],
        'timestamp' => time(),
        'username' => $_POST['username'] ?? 'anonymous',
        'userId' => $_POST['userId'] ?? null
    ];

    // Initialize videos array if it doesn't exist
    if (!isset($db['videos'])) {
        $db['videos'] = [];
    }

    // Add new video to the beginning of the array
    array_unshift($db['videos'], $newVideo);
    
    // Initialize stats for the new video
    if (!isset($db['stats'][$newVideo['id']])) {
        $db['stats'][$newVideo['id']] = [
            'views' => 0,
            'likes' => [],
            'viewedBy' => [],
            'totalComments' => 0
        ];
    }

    // Save database
    if (saveDatabase($db)) {
        $response['video'] = $newVideo;
        $response['success'] = true;
    } else {
        throw new Exception('Failed to save video data');
    }
    break;

        default:
            throw new Exception('Invalid action');
    }

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
