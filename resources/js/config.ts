// Picture rules of the crop window (resources/views/users/partials/crop.blade.php). The size limits shown here are
// the fallbacks: the crop window passes the server's own limits (max_avatar_size, max_banner_size,
// max_thumbnail_size, in KB) in its data-limits, so the browser never accepts a file the server refuses (account-21).
// Avatars are cropped square with a round preview and saved at 400 x 400; banners are cropped at the channel band's
// 16:5 and saved at 2048 x 640 (account-8: they were saved as wide as the window was on screen, 446 x 175 on a laptop).
export default {
    accepted_format : ['image/jpeg', 'image/png', 'image/webp'],
    avatar: {
        maxSize: 2,
        minWidth: 98,
        minHeight: 98,
        width: 400,
        height: 400,
        cropArea : 0.9,
        aspectRatio : 1,
        resizeable : true,
        rounded : true
    },
    banner: {
        maxSize: 5,
        minWidth: 1024,
        minHeight: 320,
        width: 2048,
        height: 640,
        cropArea : 1.0,
        aspectRatio : 16/5,
        resizeable : true,
        rounded : false
    },
    thumbnail: {
        maxSize: 2,
        minWidth: 640,
        minHeight: 360,
        width: 720,
        height: 404,
        cropArea : 1.0,
        aspectRatio : 16/9,
        resizeable : true,
        rounded : false
    }
}
