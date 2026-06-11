<template>
  <div style="width:1280px;height:3000px;overflow-y:auto">
<div class="container">
    <h1>✨ 精选产品</h1>
    <div class="subtitle">现代设计 · 卓越性能</div>
    <div class="card-grid">
        <div class="card">
            <div class="card-img">📱</div>
            <div class="card-content">
                <div class="card-title">智能手表 Pro</div>
                <div class="card-price">¥1,299</div>
                <div class="card-desc">1.9英寸AMOLED屏，血氧监测，续航7天。</div>
                <button class="btn">立即购买</button>
            </div>
        </div>
        <div class="card">
            <div class="card-img">🎧</div>
            <div class="card-content">
                <div class="card-title">降噪耳机 X3</div>
                <div class="card-price">¥899</div>
                <div class="card-desc">主动降噪，40小时续航，Hi-Res认证。</div>
                <button class="btn">立即购买</button>
            </div>
        </div>
        <div class="card">
            <div class="card-img">💻</div>
            <div class="card-content">
                <div class="card-title">超极本 Air</div>
                <div class="card-price">¥4,999</div>
                <div class="card-desc">13.3英寸2K屏，i5处理器，16GB内存。</div>
                <button class="btn">立即购买</button>
            </div>
        </div>
        <div class="card">
            <div class="card-img">📷</div>
            <div class="card-content">
                <div class="card-title">4K运动相机</div>
                <div class="card-price">¥2,299</div>
                <div class="card-desc">防水10米，电子防抖，WiFi传输。</div>
                <button class="btn">立即购买</button>
            </div>
        </div>
    </div>
</div>
  </div>
</template>
<style>
* {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            padding: 40px 24px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            margin-bottom: 12px;
            font-size: 2.5rem;
            color: #1e2a3a;
            letter-spacing: -0.5px;
        }
        .subtitle {
            text-align: center;
            color: #4a5568;
            margin-bottom: 48px;
            font-size: 1.1rem;
        }
        /* 核心 Grid 布局 */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 28px;
        }
        /* 卡片样式 */
        .card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 30px -12px rgba(0,0,0,0.2);
        }
        .card-img {
            height: 180px;
            background: linear-gradient(145deg, #3b82f6, #9333ea);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: bold;
        }
        .card-content {
            padding: 20px 20px 24px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .card-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #0f172a;
        }
        .card-price {
            font-size: 1.5rem;
            font-weight: 800;
            color: #3b82f6;
            margin: 12px 0;
        }
        .card-desc {
            color: #475569;
            line-height: 1.5;
            font-size: 0.9rem;
            margin-bottom: 20px;
            flex: 1;
        }
        .btn {
            background: #0f172a;
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            transition: background 0.2s;
            width: 100%;
        }
        .btn:hover {
            background: #3b82f6;
        }
        @media (max-width: 640px) {
            body { padding: 24px 16px; }
            .card-grid { gap: 16px; }
        }
</style>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>