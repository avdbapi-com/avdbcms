{{--
    Tools > Updates renders in the new admin's own document for every administrator, also for one who uses the old
    admin for now (v1): the page and its script exist only there. The old admin gets no new pages (F8 §2), only Home's
    "update available" link, and it stays for one release.
--}}
<x-admin.shell page="tools.updates" :legacy="false" />
