<?php
// v1.0

require_once __DIR__ . '/vendor/autoload.php';

// Load configuration
$config = require_once __DIR__ . '/config.php';

class YouTubeSearch {
    private $client;
    private $youtube;
    
    public function __construct($apiKey) {
        $this->client = new Google\Client();
        $this->client->setDeveloperKey($apiKey);
        $this->youtube = new Google\Service\YouTube($this->client);
    }
    
    public function search($query) {
        try {
            $searchResponse = $this->youtube->search->listSearch('id,snippet', [
                'q' => $query,
                'maxResults' => 10,
                'type' => 'video'
            ]);
            
            $videos = [];
            foreach ($searchResponse->getItems() as $item) {
                $videos[] = [
                    'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                    'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                    'channelTitle' => htmlspecialchars($item->getSnippet()->getChannelTitle()),
                    'channelLink' => 'https://www.youtube.com/channel/' . $item->getSnippet()->getChannelId(),
                    'videoId' => $item->getId()->getVideoId(),
                    'link' => 'https://www.youtube.com/watch?v=' . $item->getId()->getVideoId(),
                    'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl()
                ];
            }
            
            return ['success' => true, 'data' => $videos];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// API endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['query'])) {
    header('Content-Type: application/json');
    
    try {
        $youtubeSearch = new YouTubeSearch($config['youtube_api_key']);
        $results = $youtubeSearch->search($_GET['query']);
        echo json_encode($results);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Configuration error: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Search</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9f9f9;
        }
        .search-container {
            text-align: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        #search-input {
            padding: 10px;
            width: 300px;
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-right: 10px;
        }
        #search-button {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #ff0000;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        #search-button:hover {
            background-color: #cc0000;
        }
        #results-list {
            list-style: none;
            padding: 0;
        }
        .video-item {
            display: flex;
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .video-item:hover {
            transform: translateY(-2px);
        }
        .thumbnail {
            margin-right: 15px;
            min-width: 90px;  /* Reduced from 120px */
        }
        .thumbnail img {
            border-radius: 4px;
            width: 90px;     /* Reduced from 120px */
            height: auto;
        }
        .video-info {
            flex: 1;
        }
        .video-info h3 {
            margin-top: 0;
            margin-bottom: 5px;
            font-size: 16px;
        }
        .video-info .channel {
            font-size: 14px;
            color: #606060;
            margin-bottom: 8px;
        }
        .video-info .channel a {
            color: #606060;
            text-decoration: none;
        }
        .video-info .channel a:hover {
            color: #ff0000;
        }
        .video-info .description {
            font-size: 14px;
            color: #606060;
            margin: 0;
        }
        .video-info a {
            color: #000;
            text-decoration: none;
        }
        .video-info a:hover {
            color: #ff0000;
        }
        .error {
            color: #ff0000;
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="search-container">
        <input type="text" id="search-input" placeholder="Search YouTube videos...">
        <button id="search-button">Search</button>
    </div>
    <div id="results-container">
        <ul id="results-list"></ul>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('search-input');
            const searchButton = document.getElementById('search-button');
            const resultsList = document.getElementById('results-list');

            const performSearch = async () => {
                const query = encodeURIComponent(searchInput.value.trim());
                if (!query) return;

                try {
                    resultsList.innerHTML = '<div class="searching">Searching...</div>';
                    const response = await fetch(`?query=${query}`);
                    const data = await response.json();

                    resultsList.innerHTML = '';

                    if (!data.success) {
                        throw new Error(data.error);
                    }

                    if (data.data.length === 0) {
                        resultsList.innerHTML = '<div class="error">No results found.</div>';
                        return;
                    }

                    data.data.forEach(video => {
                        const li = document.createElement('li');
                        li.className = 'video-item';
                        li.innerHTML = `
                            <div class="thumbnail">
                                <img src="${video.thumbnail}" alt="${video.title}">
                            </div>
                            <div class="video-info">
                                <h3><a href="${video.link}" target="_blank">${video.title}</a></h3>
                                <div class="channel">
                                    <a href="${video.channelLink}" target="_blank">${video.channelTitle}</a>
                                </div>
                                <p class="description">${video.description}</p>
                            </div>
                        `;
                        resultsList.appendChild(li);
                    });
                } catch (error) {
                    resultsList.innerHTML = `
                        <div class="error">
                            Error fetching results: ${error.message}
                        </div>
                    `;
                }
            };

            searchButton.addEventListener('click', performSearch);
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        });
    </script>
</body>
</html>