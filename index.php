<?php
/**
 * AppName: YouTube Reseach Tool
 * Description: PHP Script search YouTube Videos, Display info on Channel  
 * Version: 2.1.3
 * Author: Jorge Pereira
 */

  
 // Enable error reporting for debugging (remove in production)
ini_set('display_errors', 0);
error_reporting(0);


require_once __DIR__ . '/vendor/autoload.php';

// Load configuration
$config = require_once __DIR__ . '/config.php';

class YouTubeSearch {
    private $client;
    private $youtube;
    private $txtRegistrationURL;
    private $appInfo;
    
    public function __construct($apiKey) {
        $this->client = new Google\Client();
        $this->client->setDeveloperKey($apiKey);
        $this->youtube = new Google\Service\YouTube($this->client);
        // Initialize appInfo in constructor
        $this->appInfo = $this->parseAppInfo();
    }

    
    public function parseAppInfo(): array {
        $defaultInfo = [
            'name' => 'App Name',
            'version' => '0.0.0',
            'author' => 'Jorge Pereira'
        ];

        // Get the content of the current file
        $content = file_get_contents(__FILE__);
        if ($content === false) {
            return $defaultInfo;
        }

        // Extract the doc block
        if (preg_match('/\/\*\*.*?\*\//s', $content, $matches)) {
            $docBlock = $matches[0];
            
            // Define patterns that exactly match your comment format
            $patterns = [
                'name' => '/\* AppName: ([^\n]+)/',
                'version' => '/\* Version: ([^\n]+)/',
                'author' => '/\* Author: ([^\n]+)/'
            ];

            $info = $defaultInfo; // Start with default values
            
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $docBlock, $matches)) {
                    $value = trim($matches[1]);
                    if (!empty($value)) {
                        $info[$key] = $value;
                    }
                }
            }
            
            return $info;
        }

        return $defaultInfo;
    }

    public function getAppInfo(): array {
        return $this->appInfo;
    }
    
    public function search($query) {
        try {
            // First get the video IDs from search
            $searchResponse = $this->youtube->search->listSearch('id,snippet', [
                'q' => $query,
                'maxResults' => 10,
                'type' => 'video'
            ]);
            
            // Collect video IDs
            $videoIds = [];
            foreach ($searchResponse->getItems() as $item) {
                $videoIds[] = $item->getId()->getVideoId();
            }
            
            // Get detailed video information including statistics
            $videosResponse = $this->youtube->videos->listVideos(
                'snippet,statistics',
                ['id' => implode(',', $videoIds)]
            );
            
            $videos = [];
            foreach ($videosResponse->getItems() as $item) {
                $videos[] = [
                    'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                    'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                    'channelTitle' => htmlspecialchars($item->getSnippet()->getChannelTitle()),
                    'channelId' => $item->getSnippet()->getChannelId(),
                    'videoId' => $item->getId(),
                    'link' => 'https://www.youtube.com/watch?v=' . $item->getId(),
                    'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                    'publishedAt' => date('F j, Y', strtotime($item->getSnippet()->getPublishedAt())),
                    'viewCount' => number_format($item->getStatistics()->getViewCount()),
                    'likeCount' => number_format($item->getStatistics()->getLikeCount())
                ];
            }
            
            return ['success' => true, 'data' => $videos];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    public function getChannelInfo($channelId) {
        try {
            // Get channel details
            $response = $this->youtube->channels->listChannels('snippet,statistics,contentDetails', [
                'id' => $channelId
            ]);
            
            if (empty($response->getItems())) {
                throw new Exception('Channel not found');
            }
            
            $channel = $response->getItems()[0];
            $snippet = $channel->getSnippet();
            $statistics = $channel->getStatistics();
            
            // Get playlists count by actually fetching the playlists
            $playlistsResponse = $this->youtube->playlists->listPlaylists('id', [
                'channelId' => $channelId,
                'maxResults' => 50 // Get maximum allowed to get a better count
            ]);
            
            // Get total results from pageInfo
            $playlistCount = $playlistsResponse->getPageInfo()->getTotalResults();
            
            return [
                'success' => true,
                'data' => [
                    'channelId' => $channel->getId(),
                    'name' => $snippet->getTitle(),
                    'owner' => $snippet->getCustomUrl() ?? 'Not available',
                    'customUrl' => $snippet->getCustomUrl() ?? 'Not available',
                    'subscriberCount' => $statistics->getSubscriberCount() ?? 'Hidden',
                    'videoCount' => $statistics->getVideoCount() ?? '0',
                    'playlistCount' => $playlistCount ?? 0,
                    'startDate' => date('F j, Y', strtotime($snippet->getPublishedAt())),
                    'description' => $snippet->getDescription()
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


public function getChannelVideos($channelId) {
    try {
        // First get channel details
        $channelResponse = $this->youtube->channels->listChannels('snippet', [
            'id' => $channelId
        ]);
        
        if (empty($channelResponse->getItems())) {
            throw new Exception('Channel not found');
        }
        
        $channel = $channelResponse->getItems()[0];
        $channelInfo = [
            'name' => $channel->getSnippet()->getTitle(),
            'id' => $channel->getId(),
            'thumbnail' => $channel->getSnippet()->getThumbnails()->getDefault()->getUrl()
        ];

        // Fetch channel videos
        $response = $this->youtube->search->listSearch('id,snippet', [
            'channelId' => $channelId,
            'type' => 'video',
            'order' => 'date', // Sort by most recent
            'maxResults' => 50
        ]);
        
        $videos = [];
        foreach ($response->getItems() as $item) {
            $videos[] = [
                'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                'channelTitle' => htmlspecialchars($item->getSnippet()->getChannelTitle()),
                'channelId' => $item->getSnippet()->getChannelId(),
                'videoId' => $item->getId()->getVideoId(),
                'link' => 'https://www.youtube.com/watch?v=' . $item->getId()->getVideoId(),
                'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                'publishedAt' => date('F j, Y', strtotime($item->getSnippet()->getPublishedAt()))
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'channel' => $channelInfo,
                'videos' => $videos
            ]
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
    
    public function getChannelPlaylists($channelId) {
        try {
            // First get channel details
            $channelResponse = $this->youtube->channels->listChannels('snippet', [
                'id' => $channelId
            ]);
            
            if (empty($channelResponse->getItems())) {
                throw new Exception('Channel not found');
            }
            
            $channel = $channelResponse->getItems()[0];
            $channelInfo = [
                'name' => $channel->getSnippet()->getTitle(),
                'id' => $channel->getId(),
                'thumbnail' => $channel->getSnippet()->getThumbnails()->getDefault()->getUrl()
            ];
    
            $playlists = [];
            $pageToken = '';
            
            // Fetch all playlists using pagination
            do {
                $params = [
                    'channelId' => $channelId,
                    'maxResults' => 50
                ];
                
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }
                
                $response = $this->youtube->playlists->listPlaylists('snippet,contentDetails', $params);
                
                foreach ($response->getItems() as $item) {
                    $playlists[] = [
                        'id' => $item->getId(),
                        'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                        'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                        'videoCount' => $item->getContentDetails()->getItemCount(),
                        'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                        'publishedAt' => date('F j, Y', strtotime($item->getSnippet()->getPublishedAt())),
                        'link' => 'https://www.youtube.com/playlist?list=' . $item->getId()
                    ];
                }
                
                $pageToken = $response->getNextPageToken();
            } while ($pageToken && count($playlists) < 200);
            
            return [
                'success' => true,
                'data' => [
                    'channel' => $channelInfo,
                    'playlists' => $playlists
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
  
}

// Initialize YouTubeSearch instance at the top level for footer access
$youtubeSearch = null;
try {
    $youtubeSearch = new YouTubeSearch($config['youtube_api_key']);
    $appInfo = $youtubeSearch->getAppInfo();
} catch (Exception $e) {
    $appInfo = [
        'name' => 'YouTube Research Tool',
        'version' => '0.0.0',
        'author' => 'Jorge Pereira'
    ];
}

// API endpoint handling
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json');

    
    try {
        $youtubeSearch = new YouTubeSearch($config['youtube_api_key']);
        
        if (isset($_GET['query'])) {
            echo json_encode($youtubeSearch->search($_GET['query']));
        } elseif (isset($_GET['channelId'])) {
            echo json_encode($youtubeSearch->getChannelInfo($_GET['channelId']));
        } elseif (isset($_GET['channelPlaylists'])) {
            echo json_encode($youtubeSearch->getChannelPlaylists($_GET['channelPlaylists']));
        } elseif (isset($_GET['channelVideos'])) {
            echo json_encode($youtubeSearch->getChannelVideos($_GET['channelVideos']));
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

      .playlist-link {
            color: #065fd4;
            text-decoration: none;
            cursor: pointer;
        }

        .playlist-link:hover {
            text-decoration: underline;
        }

        .playlist-details {
            font-size: 14px;
            color: #606060;
            margin: 5px 0;
        }

        .searching {
            text-align: center;
            padding: 20px;
            color: #606060;
            font-size: 16px;
        }

        .channel-header {
    background-color: white;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.channel-info-header {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
}

.channel-thumbnail {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    margin-right: 20px;
}

.channel-header h2 {
    margin: 0;
    font-size: 24px;
}

.channel-header h2 a {
    color: #000;
    text-decoration: none;
    transition: color 0.3s;
}

.channel-header h2 a:hover {
    color: #ff0000;
}

.channel-header h3 {
    margin: 0;
    font-size: 18px;
    color: #606060;
}

.playlist-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.video-meta {
    margin: 8px 0;
    color: #606060;
    font-size: 14px;
}

.video-stats {
    margin-bottom: 4px;
}

.video-stats .separator {
    margin: 0 8px;
}

.channel {
    margin-top: 4px;
}

.channel a {
    color: #606060;
    text-decoration: none;
    font-weight: 500;
}

.channel a:hover {
    color: #ff0000;
}

.description {
    color: #606060;
    font-size: 14px;
    margin: 8px 0 0 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
}

header {
            background-color: #343a40;
            color: white;
            padding: 1rem 0;
            margin-bottom: 2rem;
        }

footer {
            background-color: #343a40;
            color: #ffffff;
            padding: 1rem 0;
            margin-top: 2rem;
            text-align: center;
        }

        .footer-content {
            font-size: 0.9rem;
            opacity: 0.8;
        }


    </style>
</head>
<body>
<header>
        <div class="container">
            <div class="row">
                <div class="col text-center">
                    <h1>
                    <?php echo htmlspecialchars($appInfo['name']) ?>

                    </h1>
                </div>
            </div>
        </div>
</header>

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

    
    <footer>
    <div class="container">
        <div class="footer-content">
            <?php 
            // Use the global $appInfo variable
            echo htmlspecialchars($appInfo['name']) . ' | ' .
                 'Version: ' . htmlspecialchars($appInfo['version']) . ' | ' .
                 'Author: ' . htmlspecialchars($appInfo['author']);
            ?>
        </div>
    </div>
    </footer>


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
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Search failed');
        }

        elements.resultsList.innerHTML = '';

        if (data.data.length === 0) {
            elements.resultsList.innerHTML = '<div class="error">No results found.</div>';
            return;
        }

        data.data.forEach(video => {
            const li = document.createElement('li');
            li.className = 'video-item';
            li.innerHTML = `
                <div class="thumbnail">
                    <img 
                        src="${video.thumbnail}" 
                        alt="${video.title}"
                        onerror="this.src='/api/placeholder/120/90'; this.onerror=null;"
                    >
                </div>
                <div class="video-info">
                    <h3><a href="${video.link}" target="_blank">${video.title}</a></h3>
                    <div class="video-meta">
                        <div class="video-stats">
                            <span>${video.viewCount} views</span>
                            <span class="separator">•</span>
                            <span>${video.publishedAt}</span>
                            <span class="separator">•</span>
                            <span>${video.likeCount} likes</span>
                        </div>
                        <div class="channel">
                            <a class="channel-link" data-channel-id="${video.channelId}">${video.channelTitle}</a>
                        </div>
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
                <p><strong>Video Count:</strong> <a href="#" class="video-count-link" data-channel-id="${channelInfo.channelId}">${channelInfo.videoCount}</a></p>
                <p><strong>Number of Playlists:</strong> <a href="#" class="playlist-link" data-channel-id="${channelInfo.channelId}">${channelInfo.playlistCount}</a></p>
                <p><strong>Channel Start Date:</strong> ${channelInfo.startDate}</p>
                <p><strong>Channel Description:</strong> ${channelInfo.description}</p>
            </div>
            <a href="https://www.youtube.com/channel/${channelInfo.channelId}" 
               target="_blank" 
               class="channel-visit-button">
                Visit Channel
            </a>
        `;
        
        // Add event listeners for both playlist and video count links
        const playlistLink = elements.channelInfoContent.querySelector('.playlist-link');
        const videoCountLink = elements.channelInfoContent.querySelector('.video-count-link');
        
        playlistLink.addEventListener('click', async (e) => {
            e.preventDefault();
            const channelId = e.target.dataset.channelId;
            await showPlaylists(channelId);
            elements.channelModal.style.display = 'none';
        });

        videoCountLink.addEventListener('click', async (e) => {
            e.preventDefault();
            const channelId = e.target.dataset.channelId;
            await showChannelVideos(channelId);
            elements.channelModal.style.display = 'none';
        });
        
        elements.channelModal.style.display = 'block';
    } catch (error) {
        console.error('Channel info error:', error);
        alert(`Error fetching channel information: ${error.message}`);
    }
};

const showChannelVideos = async (channelId) => {
    try {
        elements.resultsList.innerHTML = '<div class="searching">Loading videos...</div>';
        
        const response = await fetch(`?channelVideos=${encodeURIComponent(channelId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error);
        }

        // Create channel header
        elements.resultsList.innerHTML = `
            <div class="channel-header">
                <div class="channel-info-header">
                    <img 
                        src="${data.data.channel.thumbnail}" 
                        alt="${data.data.channel.name}" 
                        class="channel-thumbnail"
                        onerror="this.src='/api/placeholder/80/80'; this.onerror=null;"
                    >
                    <h2>
                        <a href="https://www.youtube.com/channel/${data.data.channel.id}" target="_blank">
                            ${data.data.channel.name}
                        </a>
                    </h2>
                </div>
                <h3>Channel Videos</h3>
            </div>
        `;

        if (data.data.videos.length === 0) {
            elements.resultsList.innerHTML += '<div class="error">No videos found.</div>';
            return;
        }

        const videosList = document.createElement('ul');
        videosList.className = 'video-list';

        data.data.videos.forEach(video => {
            const li = document.createElement('li');
            li.className = 'video-item';
            li.innerHTML = `
                <div class="thumbnail">
                    <img 
                        src="${video.thumbnail}" 
                        alt="${video.title}"
                        onerror="this.src='/api/placeholder/120/90'; this.onerror=null;"
                    >
                </div>
                <div class="video-info">
                    <h3><a href="${video.link}" target="_blank">${video.title}</a></h3>
                    <div class="video-details">
                        <span>Published on ${video.publishedAt}</span>
                    </div>
                    <p class="description">${video.description}</p>
                </div>
            `;
            videosList.appendChild(li);
        });

        elements.resultsList.appendChild(videosList);

    } catch (error) {
        console.error('Videos error:', error);
        elements.resultsList.innerHTML = `
            <div class="error">Error fetching videos: ${error.message}</div>
        `;
    }
};


const showPlaylists = async (channelId) => {
    try {
        elements.resultsList.innerHTML = '<div class="searching">Loading playlists...</div>';
        
        const response = await fetch(`?channelPlaylists=${encodeURIComponent(channelId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error);
        }

        // Create channel header with fallback image handling
        elements.resultsList.innerHTML = `
            <div class="channel-header">
                <div class="channel-info-header">
                    <img 
                        src="${data.data.channel.thumbnail}" 
                        alt="${data.data.channel.name}" 
                        class="channel-thumbnail"
                        onerror="this.src='/api/placeholder/80/80'; this.onerror=null;"
                    >
                    <h2>
                        <a href="https://www.youtube.com/channel/${data.data.channel.id}" target="_blank">
                            ${data.data.channel.name}
                        </a>
                    </h2>
                </div>
                <h3>Channel Playlists</h3>
            </div>
        `;

        if (data.data.playlists.length === 0) {
            elements.resultsList.innerHTML += '<div class="error">No playlists found.</div>';
            return;
        }

        // Create playlist list with fallback image handling
        const playlistList = document.createElement('ul');
        playlistList.className = 'playlist-list';

        data.data.playlists.forEach(playlist => {
            const li = document.createElement('li');
            li.className = 'video-item';
            li.innerHTML = `
                <div class="thumbnail">
                    <img 
                        src="${playlist.thumbnail}" 
                        alt="${playlist.title}"
                        onerror="this.src='/api/placeholder/120/90'; this.onerror=null;"
                    >
                </div>
                <div class="video-info">
                    <h3><a href="${playlist.link}" target="_blank">${playlist.title}</a></h3>
                    <div class="playlist-details">
                        <span>${playlist.videoCount} videos</span> • 
                        <span>Created on ${playlist.publishedAt}</span>
                    </div>
                    <p class="description">${playlist.description}</p>
                </div>
            `;
            playlistList.appendChild(li);
        });

        elements.resultsList.appendChild(playlistList);

    } catch (error) {
        console.error('Playlist error:', error);
        elements.resultsList.innerHTML = `
            <div class="error">Error fetching playlists: ${error.message}</div>
        `;
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