=head1 NAME

 Plugin::InstantSSH

=cut

# i-MSCP InstantSSH plugin
# Copyright (C) 2014 Laurent Declercq <l.declercq@nuxwin.com>
#
# This library is free software; you can redistribute it and/or
# modify it under the terms of the GNU Lesser General Public
# License as published by the Free Software Foundation; either
# version 2.1 of the License, or (at your option) any later version.
#
# This library is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
# Lesser General Public License for more details.
#
# You should have received a copy of the GNU Lesser General Public
# License along with this library; if not, write to the Free Software
# Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301 USA

package Plugin::InstantSSH;

use strict;
use warnings;

no if $] >= 5.017011, warnings => 'experimental::smartmatch';

use lib "$main::imscpConfig{'PLUGINS_DIR'}/InstantSSH/backend",
        "$main::imscpConfig{'PLUGINS_DIR'}/InstantSSH/backend/Vendor";

use iMSCP::Debug;
use iMSCP::Database;
use iMSCP::Dir;
use iMSCP::File;
use iMSCP::Execute;
use iMSCP::Ext2Attributes qw(clearImmutable isImmutable setImmutable);
use InstantSSH::JailBuilder;
use InstantSSH::JailBuilder::Utils qw(normalizePath);
use Unix::PasswdFile;
use Unix::ShadowFile;
use JSON;

use parent 'Common::SingletonClass';

=head1 DESCRIPTION

 This package provide the backend part of the InstantSSH plugin.

=head1 PUBLIC METHODS

=over 4

=item install()

 Process plugin installation tasks

 Return int 0 on success, other on failure

=cut

sub install
{
	my $self = $_[0];

	my $rs = $self->_checkRequirements();
	return $rs if $rs;

	# Create configuration directory for makejail ( since version 2.1.0 )
	$rs = iMSCP::Dir->new( dirname => $self->{'config'}->{'makejail_confdir_path'} )->make(
		'user' => $main::imscpConfig{'ROOT_USER'}, 'group' => $main::imscpConfig{'IMSCP_GROUP'}, 'mode' => 0750
	);
	return $rs if $rs;

	$rs = $self->_configurePamChroot();
	return $rs if $rs;

	$self->_configureBusyBox();
}

=item uninstall()

 Process plugin uninstallation tasks

 Return int 0 on success, other on failure

=cut

sub uninstall
{
	my $self = $_[0];

	my $rootJailDir = normalizePath($self->{'config'}->{'root_jail_dir'});

	# Remove the jails root directory ( only if empty )
	if(-d $rootJailDir) {
		if(iMSCP::Dir->new( dirname => $rootJailDir )->isEmpty()) {
			my $rs = iMSCP::Dir->new( dirname => $rootJailDir )->remove();
			return $rs if $rs;
		} else {
			error("Cannot delete the $rootJailDir directory: Directory is not empty");
			return 1;
		}
	}

	# Remove the plugin configuration directory if any
	my $rs = iMSCP::Dir->new( dirname => $self->{'config'}->{'makejail_confdir_path'} )->remove();
	return $rs if $rs;

	$rs = $self->_configurePamChroot('uninstall');
	return $rs if $rs;

	$self->_configureBusyBox('uninstall');
}

=item update($fromVersion, $toVersion)

 Process plugin update tasks

 Param string $fromVersion
 Param string $toVersion
 Return int 0 on success, other on failure

=cut

sub update
{
	my ($self, $fromVersion, $toVersion) = @_;

	my $rs = $self->install();
	return $rs if $rs;

	require version;
	version->import();

	# Update from versions older than 2.1.0
	#  - Remove old makejail files if any
	if(version->parse("v$fromVersion") < version->parse("v2.1.0") && -d '/etc/makejail') {
		for my $file(iMSCP::Dir->new( dirname => '/etc/makejail', fileType => 'InstantSSH.*\\.py' )->getFiles()) {
			$rs = iMSCP::File->new( filename => "/etc/makejail/$file" )->delFile();
			return $rs if $rs;
		}
	}

	# Update from version older than version 3.0.0
	#  - Reset back i-MSCP unix user fields (homedir, shell) to their default values
	#  - Un-jail i-MSCP unix users
	if(version->parse("v$fromVersion") < version->parse("v3.0.0")) {
		my $adminSysNames = $self->{'db'}->doQuery(
			'admin_sys_name',
			'SELECT admin_sys_name FROM admin INNER JOIN instant_ssh_users ON(ssh_user_admin_id = admin_id)'
		);
		unless(ref $adminSysNames eq 'HASH') {
			error($adminSysNames);
			return 1;
		}

		if(%{$adminSysNames}) {
			my $rootJailDir = normalizePath($self->{'config'}->{'root_jail_dir'});

			my $pw = Unix::PasswdFile->new('/etc/passwd');

			for my $adminSysName(keys $adminSysNames) {
				my $homeDir = (getpwnam($adminSysName))[7];

				if(defined $homeDir) {
					$homeDir = normalizePath($homeDir);

					# Force logout of ssh logins if any
					my @cmd = ($main::imscpConfig{'CMD_PKILL'}, '-KILL', '-f', '-u', escapeShell($adminSysName), 'sshd');
					my ($stdout, $stderr);
					execute("@cmd", \$stdout, \$stderr);
					debug($stdout) if $stdout;
					debug($stderr) if $stderr;

					# Set the user homedir and shell fields back to their default values
					$pw->home($adminSysName, $homeDir);
					$pw->shell($adminSysName, '/bin/false');

					unless($pw->commit()) {
						error("Unable to reset homedir and shell fields of the $adminSysName unix user");
						return 1;
					}

					my $jailId = (-d "$rootJailDir/$adminSysName") ? $adminSysName : 'shared_jail';

					my $jailBuilder;
					eval {
						$jailBuilder = InstantSSH::JailBuilder->new( id => $jailId, config => $self->{'config'} );
					};
					if($@) {
						error("Unable to create JailBuilder object: $@");
						return 1;
					}

					$rs = $jailBuilder->unjailUser($adminSysName);
					return $rs if $rs;
				} else {
					error("Unable to find $adminSysName user homedir");
					return 1;
				}
			}

			undef $pw;
		}

		$rs = $self->{'db'}->doQuery('dummy', "UPDATE instant_ssh_users SET ssh_user_status = 'tochange'");
		unless(ref $rs eq 'HASH') {
			error($rs);
			return 1;
		}

		$rs = $self->run();
		return $rs if $rs;
	}

	0;
}

=item change

 Process plugin change tasks

 Return int 0, other on failure

=cut

sub change
{
	my $self = $_[0];

	unless(defined $main::execmode && $main::execmode eq 'setup') {
		my $rs = $self->{'db'}->doQuery('dummy', "UPDATE instant_ssh_users SET ssh_user_status = 'todisable'");
		unless(ref $rs eq 'HASH') {
			error($rs);
			return 1;
		}

		$rs = $self->run();
		return $rs if $rs;

		my $jailBuilder;
		eval { $jailBuilder = InstantSSH::JailBuilder->new( id => 'shared_jail', config => $self->{'config'} ); };
		if($@) {
			error("Unable to create JailBuilder object: $@");
			return 1;
		}

		# Create / Update / Remove the shared jail according the value of the shared_jail parameter
		if($self->{'config'}->{'shared_jail'}) {
			$rs = $jailBuilder->makeJail(); # Update jail
			return $rs if $rs;
		} else {
			$rs = $jailBuilder->removeJail(); # Remove jail
			return $rs if $rs;
		}

		$rs = $self->{'db'}->doQuery('dummy', "UPDATE instant_ssh_users SET ssh_user_status = 'toenable'");
		unless(ref $rs eq 'HASH') {
			error($rs);
			return 1;
		}

		$rs = $self->run();
		return $rs if $rs;
	}

	0;
}

=item enable()

 Process enable tasks

 Return int 0, other on failure

=cut

sub enable
{
	my $self = $_[0];

	if($self->{'action'} eq 'enable') {
		my $rs = $self->{'db'}->doQuery('dummy', "UPDATE instant_ssh_users SET ssh_user_status = 'toenable'");
		unless(ref $rs eq 'HASH') {
			error($rs);
			return 1;
		}

		$rs = $self->run();
		return $rs if $rs;
	}

	0;
}

=item disable()

 Process disable tasks

 Return int 0, other on failure

=cut

sub disable
{
	my $self = $_[0];

	if($self->{'action'} eq 'disable') {
		my $rs = $self->{'db'}->doQuery('dummy', "UPDATE instant_ssh_users SET ssh_user_status = 'todisable'");
		unless(ref $rs eq 'HASH') {
			error($rs);
			return 1;
		}

		$rs = $self->run();
		return $rs if $rs;

		my $jailBuilder;
		eval { $jailBuilder = InstantSSH::JailBuilder->new( id => 'shared_jail', config => $self->{'config'} ); };
		if($@) {
			error("Unable to create JailBuilder object: $@");
			return 1;
		}

		$rs = $jailBuilder->removeJail(); # Remove shared jail if any
		return $rs if $rs;
	}

	0;
}

=item run()

 Handle SSH users

 Return int 0 on succes, other on failure

=cut

sub run
{
	my $self = $_[0];

	my $dbh = $self->{'db'}->getRawDb();

	my $sth = $dbh->prepare(
		"
			SELECT
				t1.ssh_user_id, t1.ssh_user_permission_id, t1.ssh_user_admin_id, t1.ssh_user_name,
				t1.ssh_user_password, t1.ssh_user_status, COUNT(t2.ssh_user_id) AS nb_ssh_users,
				t3.ssh_permission_jailed_shell AS ssh_user_jailed
			FROM
				instant_ssh_users AS t1
			LEFT JOIN
				instant_ssh_users AS t2 ON(
						t2.ssh_user_admin_id = t1.ssh_user_admin_id
					AND
						t2.ssh_user_permission_id IS NOT NULL
					AND
						t2.ssh_user_status NOT IN('todisable', 'todelete', 'disabled')
				)
			LEFT JOIN
				instant_ssh_permissions AS t3 ON(t3.ssh_permission_id = t1.ssh_user_permission_id)
			WHERE
				t1.ssh_user_status IN('toadd', 'tochange', 'toenable', 'todisable', 'todelete')
				OR
				t1.ssh_user_permission_id IS NULL
			GROUP BY
				ssh_user_id
			ORDER BY
				ssh_user_admin_id
		"
	);
	unless($sth) {
		$self->{'RETVAL'} = 1; # Not an error related to a specific plugin item
		error("Couldn't prepare SQL statement: " . $dbh->errstr);
		return 1;
	}

	unless($sth->execute()) {
		$self->{'RETVAL'} = 1; # Not an error related to a specific plugin item
		error("Couldn't execute prepared statement: " . $dbh->errstr);
		return 1;
	}

	my ($rs, $ret) = (0, 0);

	while (my $data = $sth->fetchrow_hashref()) {
		$data->{'ssh_user_status'} = 'todelete' unless defined $data->{'ssh_user_permission_id'};

		my @sql;

		if( $data->{'ssh_user_status'} ~~ ['toadd', 'tochange', 'toenable']) {
			$rs = $self->_addSshUser($data);
			@sql = (
				'dummy', 'UPDATE instant_ssh_users SET ssh_user_status = ? WHERE ssh_user_id = ?',
				($rs ? scalar getMessageByType('error') : 'ok'),
				$data->{'ssh_user_id'}
			);
		} elsif( $data->{'ssh_user_status'} ~~ ['todisable', 'todelete']) {
			$rs = $self->_deleteSshUser($data);
			unless($rs) {
				if( $data->{'ssh_user_status'} eq 'todisable') {
					@sql = (
						'dummy', 'UPDATE instant_ssh_users SET ssh_user_status = ? WHERE ssh_user_id = ?',
						'disabled',
						$data->{'ssh_user_id'}
					);
				} else {
					@sql = ('dummy', 'DELETE FROM instant_ssh_users WHERE ssh_user_id = ?', $data->{'ssh_user_id'});
				}
			} else {
				@sql = (
					'dummy', 'UPDATE instant_ssh_users SET ssh_user_status = ? WHERE ssh_user_id = ?',
					scalar getMessageByType('error'),
					$data->{'ssh_user_id'}
				);
			}
		}

		my $qrs = $self->{'db'}->doQuery(@sql);
		unless(ref $qrs eq 'HASH') {
			$self->{'RETVAL'} = 1; # Not an error related to a specific plugin item
			error($qrs);
			$rs = 1;
		};

		$ret ||= $rs;
	}

	$ret;
}

=back

=head1 PRIVATE METHODS

=over 4

=item _init

 Initialize instance

 Return Plugin::InstantSSH

=cut

sub _init
{
	my $self = $_[0];

	$self->{'db'} = iMSCP::Database->factory();

	my $config = $self->{'db'}->doQuery(
		'plugin_name', "SELECT plugin_name, plugin_config FROM plugin WHERE plugin_name = 'InstantSSH'"
	);
	unless(ref $config eq 'HASH') {
		die("InstantSSH: $config");
	} else {
		$self->{'config'} = decode_json($config->{'InstantSSH'}->{'plugin_config'})
	}

	for(qw/makejail_confdir_path root_jail_dir shared_jail shells/) {
		die("Missing $_ configuration parameter") unless exists $self->{'config'}->{$_};
	}

	$self;
}

=item _addSshUser(\%data)

 Add / Update the given SSH user

 Param hash \%data SSH user data
 Return int 0 on success, other on failure

=cut

sub _addSshUser
{
	my($self, $data) = @_;

	my $rs = $self->{'eventManager'}->trigger('onBeforeAddSshUser', $data);
	return $rs if $rs;

	my $pUserName = $main::imscpConfig{'SYSTEM_USER_PREFIX'} .
		($main::imscpConfig{'SYSTEM_USER_MIN_UID'} + $data->{'ssh_user_admin_id'});

	if((my @pwEntry = getpwnam($pUserName))) {
		# Force logout of SSH logins if any
		my @cmd = ($main::imscpConfig{'CMD_PKILL'}, '-KILL', '-f', escapeShell("^sshd: $data->{'ssh_user_name'}"));
		my ($stdout, $stderr);
		$rs = execute("@cmd", \$stdout, \$stderr);
		debug($stdout) if $stdout;

		# Create / Update user
		my $pUserHomeDir = normalizePath($pwEntry[7]);
		my $shell = ($data->{'ssh_user_jailed'})
			? $self->{'config'}->{'shells'}->{'jailed'} : $self->{'config'}->{'shells'}->{'full'};

		unless(getpwnam($data->{'ssh_user_name'})) {
			@cmd = (
				'useradd',
				'-c', escapeShell('i-MSCP InstantSSH User'),
				'-d', (($data->{'ssh_user_jailed'}) ? escapeShell($pUserHomeDir . '/./') : escapeShell($pUserHomeDir)),
				'-g', escapeShell($pwEntry[3]),
				'-o',
				'-p', ((defined $data->{'ssh_user_password'}) ? escapeShell($data->{'ssh_user_password'}) : '!'),
				'-s', escapeShell($shell),
				'-u', escapeShell($pwEntry[2]),
				escapeShell($data->{'ssh_user_name'})
			);
			$rs = execute("@cmd", \$stdout, \$stderr);
			debug($stdout) if $stdout;
			error($stderr) if $rs && $stderr;
			return $rs if $rs;
		} else {
			if(-f '/etc/shadow') { # On some systems, shadow passwords can be disabled
				my $pw = Unix::PasswdFile->new('/etc/passwd');
				$pw->home($data->{'ssh_user_name'}, ($data->{'ssh_user_jailed'}) ? $pUserHomeDir . '/./' : $pUserHomeDir);
				$pw->shell($data->{'ssh_user_name'}, $shell);
				unless($pw->commit()) {
					error("Unable to update $data->{'ssh_user_name'} entry in /etc/passwd file");
					return 1;
				}
				undef $pw;

				my $sw = Unix::ShadowFile->new('/etc/shadow');
				$sw->passwd(
					$data->{'ssh_user_name'}, (defined $data->{'ssh_user_password'}) ? $data->{'ssh_user_password'} : '!'
				);
				unless($sw->commit()) {
					error("Unable to update $data->{'ssh_user_name'} entry in /etc/shadow file");
					return 1;
				}
				undef $sw;
			} else {
				my $pw = Unix::PasswdFile->new('/etc/passwd');
				$pw->passwd(
					$data->{'ssh_user_name'}, (defined $data->{'ssh_user_password'}) ? $data->{'ssh_user_password'} : '!'
				);
				$pw->home($data->{'ssh_user_name'}, ($data->{'ssh_user_jailed'}) ? $pUserHomeDir . '/./' : $pUserHomeDir);
				$pw->shell($data->{'ssh_user_name'}, $shell);
				unless($pw->commit()) {
					error("Unable to update $data->{'ssh_user_name'} entry in /etc/passwd file");
					return 1;
				}
				undef $pw;
			}
		}

		my $jailBuilder;
		eval {
			$jailBuilder = InstantSSH::JailBuilder->new(
				id => ($self->{'config'}->{'shared_jail'}) ? 'shared_jail' : $pUserName, config => $self->{'config'}
			);
		};
		if($@) {
			error("Unable to create JailBuilder object: $@");
			return 1;
		}

		# Jail user if needed
		if($data->{'ssh_user_jailed'}) {
			# Lock ssh user temporarely
			@cmd = ('usermod', '-s', '/bin/false', '-L', escapeShell($data->{'ssh_user_name'}));
			$rs = execute("@cmd", \$stdout, \$stderr);
			debug($stdout) if $stdout;
			error($stderr) if $rs && $stderr;
			return $rs if $rs;

			# Create jail if needed
			unless($jailBuilder->existsJail()) {
				$rs = $jailBuilder->makeJail();
				return $rs if $rs;
			}

			# Add parent user in jail to show parent user as files/directories owner
			$rs = $jailBuilder->addPasswdFile('/etc/passwd', $pUserName, '/bin/false');
			return $rs if $rs;

			# Jail user
			$rs = $jailBuilder->jailUser($data->{'ssh_user_name'}, $shell);
			return $rs if $rs;

			# Unlock user
			@cmd = ('usermod', '-s', escapeShell($shell), '-U', escapeShell($data->{'ssh_user_name'}));
			$rs = execute("@cmd", \$stdout, \$stderr);
			debug($stdout) if $stdout;
			error($stderr) if $rs && $stderr;
			return $rs if $rs;
		} elsif($data->{'ssh_user_status'} eq 'tochange') {
			# Ensure that the user is not jailed
			$rs = $jailBuilder->unjailUser($data->{'ssh_user_name'});
			return $rs if $rs;

			if($jailBuilder->existsJail()) {
				# Remove parent user from jail if any
				$rs = $jailBuilder->removePasswdFile('/etc/passwd', $pUserName);
				return $rs if $rs;

				unless($self->{'config'}->{'shared_jail'}) {
					$rs = $jailBuilder->removeJail();
					return $rs if $rs;
				}
			}
		}

		# Add / Update authorized_keys file
		$rs = $self->_updateAuthorizedKeysFile($data);
		return $rs if $rs;

		$rs = $self->{'eventManager'}->trigger('onAfterAddSshUser', $data);
		return $rs if $rs;
	} else {
		debug("$data->{'ssh_user_name'} SSH user not added/updated: Parent user $pUserName not found.");
	}

	0;
}

=item _deleteSshUser(\%data)

 Delete the given SSH user

 Param hash \%data SSH user data
 Return int 0 on success, other on failure

=cut

sub _deleteSshUser
{
	my($self, $data) = @_;

	if(getpwnam($data->{'ssh_user_name'})) {
		my $rs = $self->{'eventManager'}->trigger('onBeforeDeleteSshUser', $data);
		return $rs if $rs;

		my $pUserName = $main::imscpConfig{'SYSTEM_USER_PREFIX'} .
			($main::imscpConfig{'SYSTEM_USER_MIN_UID'} + $data->{'ssh_user_admin_id'});

		# Lock ssh user
		my @cmd = ('usermod', '-s', '/bin/false', '-L', escapeShell($data->{'ssh_user_name'}));
		my ($stdout, $stderr);
		$rs = execute("@cmd", \$stdout, \$stderr);
		debug($stdout) if $stdout;
		error($stderr) if $rs && $stderr;
		return $rs if $rs;

		# Force logout of ssh login if any
		@cmd = ($main::imscpConfig{'CMD_PKILL'}, '-KILL', '-f',  escapeShell("^sshd: $data->{'ssh_user_name'}"));
		execute("@cmd", \$stdout, \$stderr);
		debug($stdout) if $stdout;
		debug($stderr) if $stderr;

		my $jailBuilder;
		eval {
			$jailBuilder = InstantSSH::JailBuilder->new(
				id => ($self->{'config'}->{'shared_jail'}) ? 'shared_jail' : $pUserName, config => $self->{'config'}
			);
		};
		if($@) {
			error("Unable to create JailBuilder object: $@");
			return 1;
		}

		# Unjail user if needed
		$rs = $jailBuilder->unjailUser($data->{'ssh_user_name'}, ($data->{'nb_ssh_users'} eq '0') ? undef : 'userOnly');
		return $rs if $rs;

		if($jailBuilder->existsJail() && $data->{'nb_ssh_users'} eq '0') {
			# Remove parent user from jail if any
			$rs = $jailBuilder->removePasswdFile('/etc/passwd', $pUserName);
			return $rs if $rs;

			# Remove per customer jail if any
			unless($self->{'config'}->{'shared_jail'}) {
				$rs = $jailBuilder->removeJail();
				return $rs if $rs;
			}
		}

		my $pw = Unix::PasswdFile->new('/etc/passwd');
		$pw->delete($data->{'ssh_user_name'});
		unless($pw->commit()) {
			error("Unable to remove $data->{'ssh_user_name'} entry from /etc/passwd file");
			return 1;
		}
		undef $pw;

		if(-f '/etc/shadow') { # On some systems, shadow passwords can be disabled
			my $sw = Unix::ShadowFile->new('/etc/shadow');
			$sw->delete($data->{'ssh_user_name'});
			unless($sw->commit()) {
				error("Unable to remove $data->{'ssh_user_name'} entry from /etc/shadow file");
				return 1;
			}
			undef $sw;
		}

		$rs = $self->{'eventManager'}->trigger('onAfterDeleteSshUser', $data);
		return $rs if $rs;
	}

	# Update authorized_keys file
	 $self->_updateAuthorizedKeysFile($data);
}

=item _updateAuthorizedKeysFile

 Update authorized_keys which belongs to the given SSH user

 Return int 0 on success, other on failure

=cut

sub _updateAuthorizedKeysFile
{
	my ($self, $data) = @_;

	my $pUserName =
	my $pUserGroup = $main::imscpConfig{'SYSTEM_USER_PREFIX'} .
		($main::imscpConfig{'SYSTEM_USER_MIN_UID'} + $data->{'ssh_user_admin_id'});

	my $pUserHomeDir = (getpwnam($pUserName))[7];
	my $rs = 0;

	if($pUserHomeDir) {
		$pUserHomeDir = normalizePath($pUserHomeDir);

		if(-d $pUserHomeDir) {
			my $dbh = $self->{'db'}->getRawDb();
			my $sth = $dbh->prepare(
				"
					SELECT
						ssh_user_id, ssh_user_key, ssh_user_auth_options
					FROM
						instant_ssh_users
					WHERE
						ssh_user_admin_id  = ?
					AND
						ssh_user_key IS NOT NULL
					AND
						(ssh_user_status = 'ok' OR ssh_user_id = ?)
					ORDER BY
						ssh_user_id
				"
			);
			unless($sth) {
				error("Couldn't prepare SQL statement: " . $dbh->errstr);
				return 1;
			}

			unless($sth->execute($data->{'ssh_user_admin_id'}, $data->{'ssh_user_id'})) {
				error("Couldn't execute prepared statement: " . $dbh->errstr);
				return 1;
			}

			my $fileContent = '';
			while (my $key = $sth->fetchrow_hashref()) {
				if($key->{'ssh_user_id'} ne $data->{'ssh_user_id'} || $data->{'ssh_user_status'} ne 'todelete') {
					if(defined $key->{'ssh_user_auth_options'} && $key->{'ssh_user_auth_options'} ne '') {
						$fileContent .= "$key->{'ssh_user_auth_options'} ";
					}

					$fileContent .= "$key->{'ssh_user_key'}\n";
				}
			}

			my $isProtectedPuserHomeDir = isImmutable($pUserHomeDir);
			clearImmutable($pUserHomeDir) if $isProtectedPuserHomeDir;
			clearImmutable("$pUserHomeDir/.ssh", 'recursive') if -d "$pUserHomeDir/.ssh";

			if($fileContent ne '') {
				$rs = iMSCP::Dir->new( dirname => "$pUserHomeDir/.ssh" )->make(
					{ user => $pUserName, group => $pUserGroup, mode => 0700 }
				);
				return $rs if $rs;

				my $file = iMSCP::File->new( filename => "$pUserHomeDir/.ssh/authorized_keys" );
				$rs = $file->set($fileContent);
				$rs ||= $file->save();
				$rs ||= $file->mode(0600);
				$rs ||= $file->owner($pUserName, $pUserGroup);

				setImmutable("$pUserHomeDir/.ssh", 'recursive');
			} else {
				$rs = iMSCP::Dir->new( dirname => "$pUserHomeDir/.ssh")->remove();
			}

			setImmutable($pUserHomeDir) if $isProtectedPuserHomeDir;
		}
	}

	$rs;
}

=item _checkRequirements()

 Check for requirements

 Return int 0 if all requirements are meet, other otherwise

=cut

sub _checkRequirements
{
	my $ret = 0;

	for my $package (qw/libpam-chroot makejail/) {
		my ($stdout, $stderr);
		my $rs = execute(
			"LANG=C dpkg-query --show --showformat '\${Status}' $package | cut -d ' ' -f 3", \$stdout, \$stderr
		);
		debug($stdout) if $stdout;
		if($stdout ne 'installed') {
			error("The $_ package is not installed on your system");
			$ret ||= 1;
		}
	}

	# Process dedicated test for busybox
	# This allow the admin to install either the busybox package, the usybox-static package or a self-compiled version
	unless(-x '/bin/busybox') {
		error("The busybox package is not installed on your system");
		$ret ||= 1;
	}

	$ret;
}

=item _configurePamChroot($uninstall = FALSE)

 Configure pam chroot

 Param bool $uninstall OPTIONAL Whether pam chroot configuration must be removed (default: FALSE)
 Return int 0 on success, other on failure

=cut

sub _configurePamChroot
{
	my $uninstall = $_[1] || undef;

	if(-f '/etc/pam.d/sshd') {
		my $file = iMSCP::File->new( filename => '/etc/pam.d/sshd' );

		my $fileContent = $file->get();
		unless(defined $fileContent) {
			error('Unable to read file /etc/pam.d/sshd');
			return 1;
		}

		unless($uninstall) {
			# Remove any pam_motd.so and pam_chroot.so lines
			# Note: pam_motd lines must be moved below the pam_chroot declaration because we want read motd from jail
			$fileContent =~ s/^session\s+.*?pam_(?:chroot|motd)\.so.*?\n//gm;

			$fileContent .= "session required pam_chroot.so debug\n";
			$fileContent .= "session optional pam_motd.so motd=/run/motd.dynamic\n";

			# The pam_motd module shipped with libpam-modules versions oldest than 1.1.3-7 doesn't provide the
			# 'noupdate' option. Thus, we must check the package version

			my ($stdout, $stderr);
			my $rs = execute('dpkg-query --show --showformat \'${Version}\' libpam-modules', \$stdout, \$stderr);
			debug($stdout) if $stdout;
			error($stderr) if $rs && $stderr;
			return $rs if $rs;

			my $ret = execute("dpkg --compare-versions $stdout lt 1.1.3-7", \$stdout, \$stderr);
			error($stderr) if $stderr;
			return 1 if $stderr;

			$fileContent .= ($ret) ? "session optional pam_motd.so noupdate\n" : "session optional pam_motd.so\n";
		} else {
			$fileContent =~ s/^session\s+.*?pam_chroot\.so.*?\n//gm;
		}

		my $rs = $file->set($fileContent);
		return $rs if $rs;

		$rs = $file->save();
		return $rs if $rs;
	} else {
		error('File /etc/pam.d/sshd not found');
		return 1;
	}

	0;
}

=item _configureBusyBox($uninstall = FALSE)

 Configure BusyBox

 Param bool $uninstall OPTIONAL Whether BusyBox configuration must be removed (default: FALSE)
 Return int 0 on success, other on failure

=cut

sub _configureBusyBox
{
	my $uninstall = $_[1] || undef;

	# Handle /bin/ash symlink

	if(-e '/bin/ash') {
		if(-s _) {
			unless(unlink('/bin/ash')) {
				error('Unable to remove /bin/ash symlink');
				return 1;
			}
		} else {
			error('/bin/ash should be a symlink');
			return 1;
		}
	}

	unless($uninstall) {
		unless(symlink('/bin/busybox', '/bin/ash')) {
			error("Unable to create /bin/ash symlink to /bin/busybox: $!");
			return 1;
		}
	}

	# Handle /etc/shells file
	if(-f '/etc/shells') {
		my $file = iMSCP::File->new( filename => '/etc/shells' );

		my $fileContent = $file->get();
		unless(defined $fileContent) {
			error('Unable to read /etc/shells');
			return 1;
		}

		$fileContent =~ s%^/bin/ash\n%%gm;
		$fileContent .= "/bin/ash\n" unless $uninstall;

		my $rs = $file->set($fileContent);
		return $rs if $rs;

		$rs = $file->save();
		return $rs if $rs;
	}

	0;
}

=back

=head1 AUTHOR

 Laurent Declercq <l.declercq@nuxwin.com>

=cut

1;
__END__
