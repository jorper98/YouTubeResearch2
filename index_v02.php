<?php
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
                    'channelId' => $item->getSnippet()->getChannelId(),
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
    
    public function getChannelInfo($channelId) {
        try {
            $response = $this->youtube->channels->listChannels('snippet,statistics,contentDetails', [
                'id' => $channelId
            ]);
            
            if (empty($response->getItems())) {
                throw new Exception('Channel not found');
            }
            
            $channel = $response->getItems()[0];
            $snippet = $channel->getSnippet();
            $statistics = $channel->getStatistics();
            
            return [
                'success' => true,
                'data' => [
                    'channelId' => $channel->getId(),
                    'name' => $snippet->getTitle(),
                    'owner' => $snippet->getCustomUrl() ?? 'Not available',
                    'customUrl' => $snippet->getCustomUrl() ?? 'Not available',
                    'subscriberCount' => $statistics->getSubscriberCount() ?? 'Hidden',
                    'videoCount' => $statistics->getVideoCount() ?? '0',
                    'startDate' => date('F j, Y', strtotime($snippet->getPublishedAt())),
                    'description' => $snippet->getDescription(),
                    'thumbnails' => $snippet->getThumbnails()
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// Check if this is an API request
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json');
    
    try {
        $youtubeSearch = new YouTubeSearch($config['youtube_api_key']);
        
        if (isset($_GET['query'])) {
            echo json_encode($youtubeSearch->search($_GET['query']));
        } elseif (isset($_GET['channelId'])) {
            echo json_encode($youtubeSearch->getChannelInfo($_GET['channelId']));
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
        }
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


        #channel-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            z-index: 1000;
        }
        .modal-content {
            position: relative;
            background-color: white;
            margin: 50px auto;
            padding: 20px;
            width: 80%;
            max-width: 800px;
            border-radius: 8px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .close-button {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 24px;
            cursor: pointer;
            color: #606060;
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

    <!-- Ensure modal is present in DOM before JavaScript loads -->
    <div id="channel-modal" style="display: none;">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <div id="channel-info-content"></div>
        </div>
    </div>

    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', () => {
            // Cache DOM elements
            const elements = {
                searchInput: document.getElementById('search-input'),
                searchButton: document.getElementById('search-button'),
                resultsList: document.getElementById('results-list'),
                channelModal: document.getElementById('channel-modal'),
                channelInfoContent: document.getElementById('channel-info-content'),
                closeButton: document.querySelector('.close-button')
            };

            // Verify all elements exist
            const missingElements = Object.entries(elements)
                .filter(([key, element]) => !element)
                .map(([key]) => key);

            if (missingElements.length > 0) {
                console.error('Missing elements:', missingElements);
                return;
            }

            // Search functionality
            const performSearch = async () => {
    const query = elements.searchInput.value.trim();
    if (!query) return;

    try {
        elements.resultsList.innerHTML = '<div class="searching">Searching...</div>';
        const response = await fetch(`?query=${encodeURIComponent(query)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        elements.resultsList.innerHTML = '';

        if (!data.success) {
            throw new Error(data.error || 'Search failed');
        }

        if (data.data.length === 0) {
            elements.resultsList.innerHTML = '<div class="error">No results found.</div>';
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
                        <a class="channel-link" data-channel-id="${video.channelId}">${video.channelTitle}</a>
                    </div>
                    <p class="description">${video.description}</p>
                </div>
            `;
            elements.resultsList.appendChild(li);
        });
    } catch (error) {
        console.error('Search error:', error);
        elements.resultsList.innerHTML = `
            <div class="error">Error fetching results: ${error.message}</div>
        `;
    }
};

const getChannelInfo = async (channelId) => {
    if (!channelId) return;

    try {
        const response = await fetch(`?channelId=${encodeURIComponent(channelId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error);
        }

        const channelInfo = data.data;
        elements.channelInfoContent.innerHTML = `
            <h2>${channelInfo.name}</h2>
            <div class="channel-info">
                <p><strong>Channel ID:</strong> ${channelInfo.channelId}</p>
                <p><strong>Channel Name:</strong> ${channelInfo.name}</p>
                <p><strong>Channel Owner:</strong> ${channelInfo.owner}</p>
                <p><strong>Custom URL:</strong> ${channelInfo.customUrl}</p>
                <p><strong>Subscriber Count:</strong> ${channelInfo.subscriberCount}</p>
                <p><strong>Video Count:</strong> ${channelInfo.videoCount}</p>
                <p><strong>Channel Start Date:</strong> ${channelInfo.startDate}</p>
                <p><strong>Channel Description:</strong> ${channelInfo.description}</p>
            </div>
            <a href="https://www.youtube.com/channel/${channelInfo.channelId}" 
               target="_blank" 
               class="channel-visit-button">
                Visit Channel
            </a>
        `;
        
        if (elements.channelModal) {
            elements.channelModal.style.display = 'block';
        }
    } catch (error) {
        console.error('Channel info error:', error);
        alert(`Error fetching channel information: ${error.message}`);
    }
};

            // Event listeners
            elements.searchButton.addEventListener('click', performSearch);
            
            elements.searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });

            elements.resultsList.addEventListener('click', (e) => {
                const channelLink = e.target.closest('.channel-link');
                if (channelLink && channelLink.dataset.channelId) {
                    e.preventDefault();
                    getChannelInfo(channelLink.dataset.channelId);
                }
            });

            elements.closeButton.addEventListener('click', () => {
                elements.channelModal.style.display = 'none';
            });

            // Close modal when clicking outside
            window.addEventListener('click', (e) => {
                if (e.target === elements.channelModal) {
                    elements.channelModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>