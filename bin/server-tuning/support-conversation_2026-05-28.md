# Support Conversation with SpinupWP Support

I host caseantiques.com on a server I manage with SpinupWP. The below is a support conversation I had with Jaime at SpinupWP:

## Original Support Request from MWENDER - 05/28/2026 (09:05)

My client reported seeing a 504 error when trying to access caseantiques.com this morning around 7:15am. Can you investigate to see what might have caused that?

Details:
- Server: web3.caseantiques.com (174.138.66.201)
- I give you permission to SSH into the server.

## Reply from Jaime A. at SpinupWP - 05/28/2026 (09:30)

Hey Michael,

I took a look at your server logs and it seems the site ran out of workers around 6:58am today:

[28-May-2026 06:57:56] WARNING: [pool caseantiques] seems busy (you may need to increase pm.start_servers, or pm.min/max_spare_servers), spawning 8 children, there are 0 idle, and 4 total children
[28-May-2026 06:57:57] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it
[28-May-2026 07:32:56] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it
[28-May-2026 07:39:01] WARNING: [pool caseantiques] seems busy (you may need to increase pm.start_servers, or pm.min/max_spare_servers), spawning 8 children, there are 0 idle, and 4 total children
[28-May-2026 07:39:04] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it
[28-May-2026 07:40:18] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it
[28-May-2026 07:50:59] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it
[28-May-2026 07:51:03] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it
[28-May-2026 08:57:03] WARNING: [pool caseantiques] server reached pm.max_children setting (5), consider raising it

I went ahead and raised it to 20 on your dashboard, and also adjusted the memory limit value to match Elementor's recommended one (since I noticed your site was using it).

Now, as to why it ran out of workers... From your access.log, it seems to me, that your site was getting hit by bots from all over the place. I'd suggest putting this site behind a WAF and perhaps apply region blocks on those regions you are not expecting to serve. Also, consider installing Wordfence on the site as well, even the free version can stop some of these bots from engaging with your site.

Let us know if there's anything else we can assist you with.