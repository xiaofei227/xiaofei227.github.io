<?php
// index.php - 抖音视频解析API
class DouyinParser {
    
    private $headers = [
        'User-Agent: Mozilla/5.0 (Linux; Android 8.0.0; SM-G955U Build/R16NW) AppleWebKit/537.36',
        'Referer: https://www.douyin.com/'
    ];
    
    public function parse($input) {
        try {
            if (empty($input)) {
                throw new Exception('请输入抖音链接或视频ID');
            }
            
            if (is_numeric($input)) {
                $video_id = $input;
            } else {
                // 安全提取URL
                preg_match('/https?:\/\/[^\s]+/', $input, $video_url);
                if(empty($video_url[0])) {
                    throw new Exception('无效的链接格式');
                }
                
                $redirected_url = $this->get_redirected_url($video_url[0]);
                if(empty($redirected_url)) {
                    throw new Exception('无法获取重定向URL');
                }
                
                preg_match('/(\d+)/', $redirected_url, $matches);
                $video_id = isset($matches[1]) ? $matches[1] : '';
                
                if(empty($video_id)) {
                    throw new Exception('无法提取视频ID');
                }
            }
            
            return $this->get_video_info($video_id);
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 400
            ];
        }
    }
    
    private function get_redirected_url($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        curl_exec($ch);
        if(curl_errno($ch)) {
            curl_close($ch);
            return false;
        }
        $redirected_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $redirected_url;
    }
    
    private function get_video_info($video_id) {
        $url = "https://www.iesdouyin.com/share/video/{$video_id}/";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        if(curl_errno($ch)) {
            curl_close($ch);
            throw new Exception('请求失败: ' . curl_error($ch));
        }
        curl_close($ch);
        
        if(empty($response)) {
            throw new Exception('获取视频信息失败，请检查链接是否正确');
        }
        
        // 使用更稳定的正则匹配
        if(preg_match('/window\._ROUTER_DATA\s*=\s*(\{.*?\});?</s', $response, $matches)) {
            $jsonData = json_decode($matches[1], true);
        } elseif(preg_match('/<script[^>]*id="RENDER_DATA"[^>]*>(.*?)<\/script>/', $response, $matches)) {
            $jsonData = json_decode(urldecode($matches[1]), true);
        } else {
            throw new Exception('无法解析视频数据');
        }
        
        // 安全地访问数组元素
        if(empty($jsonData) || !is_array($jsonData)) {
            throw new Exception('视频数据解析失败');
        }
        
        // 根据不同的数据格式处理
        if(isset($jsonData['loaderData']['video_(id)/page']['videoInfoRes']['item_list'][0])) {
            $itemList = $jsonData['loaderData']['video_(id)/page']['videoInfoRes']['item_list'][0];
        } elseif(isset($jsonData['videoInfoRes']['item_list'][0])) {
            $itemList = $jsonData['videoInfoRes']['item_list'][0];
        } else {
            throw new Exception('视频信息格式不正确');
        }
        
        $nickname = isset($itemList['author']['nickname']) ? $itemList['author']['nickname'] : '未知用户';
        $title = isset($itemList['desc']) ? $itemList['desc'] : '无标题';
        $awemeId = isset($itemList['aweme_id']) ? $itemList['aweme_id'] : $video_id;
        
        // 获取视频URL - 修改为iesdouyin.com域名并添加ratio=1080p参数
        $videoUrl = null;
        if(isset($itemList['video']['play_addr']['uri'])) {
            $video = $itemList['video']['play_addr']['uri'];
            $videoUrl = (strpos($video, 'mp3') === false) ? 
                'http://www.iesdouyin.com/aweme/v1/play/?video_id=' . $video . '&ratio=1080p&line=0' : $video;
        }
        
        // 获取封面
        $cover = '';
        if(isset($itemList['video']['cover']['url_list'][0])) {
            $cover = $itemList['video']['cover']['url_list'][0];
        }
        
        // 获取图片（如果是图集）
        $images = [];
        if(isset($itemList['images']) && is_array($itemList['images'])) {
            foreach($itemList['images'] as $image) {
                if(isset($image['url_list'][0])) {
                    $images[] = $image['url_list'][0];
                }
            }
        }
        
        return [
            'success' => true,
            'author' => $nickname,
            'title' => $title,
            'video_id' => $awemeId,
            'video_url' => $videoUrl,
            'play_url' => $videoUrl ? $this->get_redirected_url($videoUrl) : null,
            'cover' => $cover,
            'images' => $images,
            'type' => empty($images) ? 'video' : 'image',
            'timestamp' => time()
        ];
    }
}

// API处理
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 关闭错误显示
error_reporting(0);
ini_set('display_errors', 0);

try {
    $parser = new DouyinParser();
    
    // 支持多种参数传递方式
    if(isset($_GET['url'])) {
        $input = $_GET['url'];
    } elseif(isset($_GET['msg'])) {
        $input = $_GET['msg'];
    } elseif(isset($_POST['url'])) {
        $input = $_POST['url'];
    } elseif(isset($_POST['msg'])) {
        $input = $_POST['msg'];
    } elseif(isset($_REQUEST['url'])) {
        $input = $_REQUEST['url'];
    } else {
        $input = '';
    }
    
    $input = urldecode(trim($input));
    
    $result = $parser->parse($input);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch(Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => '系统错误: ' . $e->getMessage(),
        'code' => 500
    ], JSON_UNESCAPED_UNICODE);
}
?>