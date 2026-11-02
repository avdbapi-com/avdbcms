{{-- Round 3 R3-083: after too many wrong database passwords from all addresses together, the owner of an unfinished
     installation continues it with the password AND an empty file the page names, made in the site's storage/app folder
     through the hosting's file manager (InstallController::resume()): strangers can no longer hold it shut, and they still
     cannot guess from many addresses (each browser is shown a name of its own). $pending: InstallController::publicPending().
     The two steps are a numbered list, and the name stands whole on a line of its own with the installer's Copy button
     (the page's [data-copy] script, as the cron command and the server snippet have it), so the owner copies it instead
     of retyping it: it never breaks inside the name (where a phone is too narrow for it, its box scrolls sideways). The
     box takes the width of its step and never more ("width: 0; min-width: 100%": the unbreakable name must not widen the
     page's one-column grid, which pushed the whole panel out of the page on phones). --}}
@if(! empty($pending['resume_file']))
    <div class="notice warn" style="margin-top: 18px;" data-resume-file>
        <strong>One more step: a file only the site's owner can make</strong>
        <span>Too many wrong passwords were tried on this page from other addresses, so the installer now also asks for a file.</span>
        <ol style="margin: 6px 0 0; padding-inline-start: 1.4em;">
            <li>
                With your hosting's file manager, create an empty file with exactly this name in the folder <code>storage/<wbr>app</code> of this site. The name is for this browser only.
                <div class="config-wrap" style="margin: 8px 0 10px; width: 0; min-width: 100%;">
                    <div class="config-toolbar">
                        <span>File name @if($pending['resume_file_present'] ?? false)<span style="color: #aff3c8;">(it is there now)</span>@endif</span>
                        <button class="copy-btn" type="button" data-copy="resume-file-name" aria-label="Copy the file name">Copy</button>
                    </div>
                    {{-- a soft edge shows on the side where more of the name is (only while it does not fit: the "local"
                         covers move with the name and hide the edges once its end is in view) --}}
                    <pre id="resume-file-name" class="config-snippet" style="padding: 10px 12px; user-select: all; -webkit-user-select: all;
                        background: linear-gradient(to right, #050812 40%, rgba(5, 8, 18, 0)) left center / 24px 100% no-repeat local,
                        linear-gradient(to left, #050812 40%, rgba(5, 8, 18, 0)) right center / 24px 100% no-repeat local,
                        linear-gradient(to right, rgba(255, 255, 255, .26), rgba(255, 255, 255, 0)) left center / 12px 100% no-repeat scroll,
                        linear-gradient(to left, rgba(255, 255, 255, .26), rgba(255, 255, 255, 0)) right center / 12px 100% no-repeat scroll, #050812;"
                        data-resume-file-name>{{ $pending['resume_file'] }}</pre>
                </div>
            </li>
            <li>Enter the database password below in this browser. The installer deletes the file when you continue.</li>
        </ol>
    </div>
@endif
