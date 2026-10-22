/* promise-pam-auth (container build) - setuid-root shim.
 *
 * php_i2 execs the WebPAM auth helper as www-data (the php-fpm worker). On the
 * TrueNAS app the real credential check runs the host's `midclt` via nsenter,
 * which needs root - so this tiny setuid-root shim regains root and re-execs the
 * midclt backend under a FIXED python3 in isolated mode (-I), with a sanitized
 * environment. That way a www-data-controlled environment (PYTHONPATH, LD_*,
 * PATH, ...) cannot inject code into the root process. The host edition's
 * promise-pam-auth is likewise setuid-root (it has to read /etc/shadow), so this
 * matches the project's existing security model.
 *
 * Contract (identical to promise-pam-auth): argv[1] = username, password on
 * stdin (one line), exit 0 = authenticated, non-zero = denied/error.
 *
 * Install: root:root, mode 4755. The python backend it execs MUST be root-owned
 * and not www-data-writable, or www-data could swap it and run code as root.
 */
#include <unistd.h>

int main(int argc, char **argv)
{
    if (argc < 2)
        return 2;

    /* Regain full root from the setuid bit before touching the host. gid first,
     * while euid is still 0. */
    if (setgid(0) != 0 || setuid(0) != 0)
        return 2;

    /* Fixed interpreter + -I (ignore PYTHON* env, no user site) + a minimal,
     * fixed environment. VESSAPP_PROBE_NSENTER=1 tells the backend to nsenter
     * into the host for midclt (always required in the container). */
    char *const envp[] = {
        "PATH=/usr/sbin:/usr/bin:/sbin:/bin",
        "VESSAPP_PROBE_NSENTER=1",
        (char *)0
    };
    char *const args[] = {
        "python3", "-I",
        "/opt/promise/bin/promise-midclt-auth",
        argv[1],
        (char *)0
    };
    execve("/usr/bin/python3", args, envp);
    return 127;   /* exec failed */
}
