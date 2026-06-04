import base64

content = r'''# Px 框架

| 256-274 | right/bottom 解析仅在 absolute 模式下生效, 与 CSS 规范 relative/absolute 都支持 right/bottom 不符 |
| 233-243 | flex:1 在 width 缺失时按 parent->w - left 撑开 |
'''

b64 = base64.b64encode(content.encode(chr(117) + chr(116) + chr(102) + chr(45) + chr(8))).decode(chr(97) + chr(115) + chr(99) + chr(105) + chr(105))
print(chr(79) + chr(75) + chr(58) + b64[:50])
