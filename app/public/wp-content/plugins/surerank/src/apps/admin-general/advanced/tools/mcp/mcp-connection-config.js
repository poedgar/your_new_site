import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Text, toast, Tabs } from '@bsf/force-ui';
import { Tooltip } from '@AdminComponents/tooltip';
import { Copy, Check, ExternalLink, Sparkles } from 'lucide-react';

const copyToClipboard = ( text ) => {
	if ( ! window.navigator?.clipboard?.writeText ) {
		return Promise.reject(
			new Error( 'Clipboard API is not available in this context.' )
		);
	}
	return window.navigator.clipboard.writeText( text );
};

const McpConnectionConfig = () => {
	const [ activeTab, setActiveTab ] = useState( 'surerank-only' );
	const [ copied, setCopied ] = useState( false );
	const [ promptCopied, setPromptCopied ] = useState( false );

	const globals =
		( typeof window !== 'undefined' && window.surerank_globals ) || {};
	const restUrl = globals.mcp_rest_url || '';
	const username = globals.mcp_username || '';
	const applicationPasswordsUrl = globals.mcp_app_password_url || '';

	// Derive a clean server name from the site hostname.
	const siteHostname = restUrl
		? new URL( restUrl ).hostname.replace( /[^a-zA-Z0-9-]/g, '-' )
		: 'wordpress';

	// Compute config based on active tab.
	const isSurerankOnly = 'surerank-only' === activeTab;
	const serverName = isSurerankOnly
		? `${ siteHostname }-surerank`
		: `${ siteHostname }-global`;
	const serverUrl = isSurerankOnly
		? `${ restUrl }surerank/v1/mcp`
		: `${ restUrl }mcp/mcp-adapter-default-server`;

	const mcpServerEntry = {
		command: 'npx',
		args: [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
		env: {
			WP_API_URL: serverUrl,
			WP_API_USERNAME: username,
			WP_API_PASSWORD: 'your-application-password',
			// The proxy defaults to its OAuth flow; Application Passwords need it off.
			OAUTH_ENABLED: 'false',
		},
	};

	// VS Code expects a top-level "servers" key; every other client uses "mcpServers".
	const [ configFormat, setConfigFormat ] = useState( 'mcpServers' );
	const mcpConfig = JSON.stringify(
		{
			[ configFormat ]: {
				[ serverName ]: mcpServerEntry,
			},
		},
		null,
		2
	);

	const serverEntryJson = JSON.stringify(
		{ [ serverName ]: mcpServerEntry },
		null,
		2
	);

	const aiPrompt = `Add this MCP server to my AI client so I can connect to my WordPress site:

${ serverEntryJson }

Steps:

1. Ask which AI client I use if unsure (Claude Code, Claude Desktop, Cursor, Windsurf, VS Code, ChatGPT, etc.) and what OS I'm on (macOS, Windows, Linux). Both are needed to locate the correct config file.

2. If my client is ChatGPT: stop here, before asking for anything else. ChatGPT connectors only support OAuth and this WordPress site does not provide OAuth yet, so there is no working ChatGPT setup — tell me that clearly instead of improvising one. This config works with MCP clients that support HTTP Basic auth (Claude Code, Claude Desktop, Cursor, Windsurf, VS Code).

3. Application Password: by default, keep the "your-application-password" placeholder, finish the setup, and at the end tell me exactly which file and line to edit myself — do not ask me to paste the password into this conversation. Only substitute a real value if I volunteer it unprompted. If I don't have one yet, direct me to: ${ applicationPasswordsUrl }

4. Locate the correct MCP config file for my client and OS. Verify the path exists on my system. Do not guess. If it doesn't exist, confirm the path with me before creating.

5. Merge into existing config if present. Do NOT overwrite other MCP server entries. For VS Code, use "servers" key instead of "mcpServers". Keep "OAUTH_ENABLED": "false" — the proxy defaults to an OAuth flow otherwise, and this site authenticates with an Application Password.

6. Detect my Node.js setup.
   - macOS/Linux: which npx && node --version && echo $PATH
   - Windows (PowerShell): Get-Command npx | Select-Object -ExpandProperty Source; node --version; $env:Path
   - Windows (cmd): where npx && node --version && echo %PATH%
   - If you can run terminal commands directly (e.g. Claude Code, Cursor, Windsurf, VS Code agent), run this yourself.
   - If you are sandboxed and cannot run terminal commands (e.g. Claude Desktop), ask me to run it and paste the output back.
   - Requires Node.js v20 or newer (an LTS version is safest). If below v20, warn me and stop.
   - Set "command" to the full npx path from the output above. Never use a path from your own environment.
   - Add "PATH" to "env" using my actual PATH output. Include the node bin directory, Homebrew paths (/opt/homebrew/bin, /usr/local/bin), and system paths. MCP servers don't inherit the user's shell PATH, so include everything needed for node, npx, and git.

7. If you're sandboxed (e.g., Claude Desktop) and can't write to the real filesystem, don't write to a sandbox path. Instead, output the final JSON in a code block with the file path so I can save it manually.

8. Verify the final JSON is valid and confirm what was added.`;

	const handleCopy = () => {
		copyToClipboard( mcpConfig )
			.then( () => {
				setCopied( true );
				toast.success( __( 'Copied to clipboard', 'surerank' ) );
				setTimeout( () => setCopied( false ), 2000 );
			} )
			.catch( () => {
				toast.error( __( 'Failed to copy to clipboard', 'surerank' ) );
			} );
	};

	const handleCopyPrompt = () => {
		copyToClipboard( aiPrompt )
			.then( () => {
				setPromptCopied( true );
				toast.success(
					__(
						'Prompt copied! Paste it into your AI client.',
						'surerank'
					)
				);
				setTimeout( () => setPromptCopied( false ), 2000 );
			} )
			.catch( () => {
				toast.error(
					__( 'Failed to copy prompt to clipboard', 'surerank' )
				);
			} );
	};

	return (
		<div className="flex flex-col gap-4">
			<Text as="h5" size={ 14 } weight={ 600 }>
				{ __( 'Connect Your AI Client', 'surerank' ) }
			</Text>
			<ol className="list-decimal list-outside flex flex-col gap-1.5 text-text-secondary text-sm my-0 ml-5">
				<li>
					{ __( 'Create an Application Password', 'surerank' ) }
					{ ': ' }
					<a
						href={ applicationPasswordsUrl }
						target="_blank"
						rel="noopener noreferrer"
						className="cursor-pointer text-link-primary hover:text-link-primary-hover inline-flex items-center gap-1"
					>
						{ __( 'Open Application Passwords', 'surerank' ) }
						<ExternalLink size={ 14 } />
					</a>
				</li>
				<li>
					{ __( 'Add to your AI client:', 'surerank' ) }{ ' ' }
					<a
						href="https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/"
						target="_blank"
						rel="noopener noreferrer"
						className="cursor-pointer text-link-primary hover:text-link-primary-hover inline-flex items-center gap-1"
					>
						{ __( 'Learn More', 'surerank' ) }
						<ExternalLink size={ 14 } />
					</a>
					<ul className="list-disc list-outside flex flex-col mt-2.5 ml-4">
						<li>
							<span className="font-medium">
								{ __( 'Claude Code', 'surerank' ) }
							</span>
							{ ': .mcp.json' }
						</li>
						<li>
							<span className="font-medium">
								{ __( 'Claude Desktop', 'surerank' ) }
							</span>
							{ ': claude_desktop_config.json' }
						</li>
						<li>
							<span className="font-medium">
								{ __( 'Cursor', 'surerank' ) }
							</span>
							{ ': .cursor/mcp.json' }
						</li>
						<li>
							<span className="font-medium">
								{ __( 'VS Code', 'surerank' ) }
							</span>
							{ ': .vscode/mcp.json ' }
							{ __(
								'(use a top-level "servers" key instead of "mcpServers")',
								'surerank'
							) }
						</li>
						<li>
							<span className="font-medium">
								{ __( 'ChatGPT', 'surerank' ) }
							</span>
							{ ': ' }
							{ __(
								'Not supported yet - ChatGPT connectors require OAuth and do not accept Application Passwords.',
								'surerank'
							) }
						</li>
					</ul>
				</li>
				<li>
					{ __(
						'Replace "your-application-password" in the JSON with the password from Step 1.',
						'surerank'
					) }
				</li>
			</ol>
			<div className="flex items-center gap-3 flex-wrap">
				<Tabs activeItem={ activeTab }>
					<Tabs.Group
						className="w-max whitespace-nowrap bg-background-secondary"
						activeItem={ activeTab }
						onChange={ ( { value } ) => {
							setActiveTab( value?.slug || value );
							setCopied( false );
							setPromptCopied( false );
						} }
						variant="rounded"
						size="xs"
					>
						<Tabs.Tab
							slug="surerank-only"
							text={ __( 'SureRank Only', 'surerank' ) }
						/>
						<Tabs.Tab
							slug="global"
							text={ __( 'Global', 'surerank' ) }
						/>
					</Tabs.Group>
				</Tabs>
				<Tabs activeItem={ configFormat }>
					<Tabs.Group
						className="w-max whitespace-nowrap bg-background-secondary"
						activeItem={ configFormat }
						onChange={ ( { value } ) => {
							setConfigFormat( value?.slug || value );
							setCopied( false );
						} }
						variant="rounded"
						size="xs"
					>
						<Tabs.Tab
							slug="mcpServers"
							text={ __(
								'Claude / Cursor / Windsurf',
								'surerank'
							) }
						/>
						<Tabs.Tab
							slug="servers"
							text={ __( 'VS Code', 'surerank' ) }
						/>
					</Tabs.Group>
				</Tabs>
			</div>
			<div
				className="relative rounded-lg"
				style={ { backgroundColor: '#1c1331' } }
			>
				<div className="absolute top-3 right-3 flex items-center gap-1">
					<Tooltip
						content={ __(
							'Copy setup prompt for AI client',
							'surerank'
						) }
						placement="top"
						className="z-[99999]"
						delay={ 50 }
						arrow
					>
						<button
							onClick={ handleCopyPrompt }
							className="flex items-center justify-center w-8 h-8 rounded-md transition-colors cursor-pointer bg-transparent border-0"
							style={ { color: '#a6adc8' } }
							onMouseEnter={ ( e ) =>
								( e.currentTarget.style.backgroundColor =
									'#2a1f42' )
							}
							onMouseLeave={ ( e ) =>
								( e.currentTarget.style.backgroundColor =
									'transparent' )
							}
							aria-label={ __(
								'Copy setup prompt for AI client',
								'surerank'
							) }
						>
							{ promptCopied ? (
								<Check
									size={ 16 }
									style={ { color: '#a6e3a1' } }
								/>
							) : (
								<Sparkles size={ 16 } />
							) }
						</button>
					</Tooltip>
					<Tooltip
						content={ __( 'Copy JSON config', 'surerank' ) }
						placement="top"
						className="z-[99999]"
						delay={ 50 }
						arrow
					>
						<button
							onClick={ handleCopy }
							className="flex items-center justify-center w-8 h-8 rounded-md transition-colors cursor-pointer bg-transparent border-0"
							style={ { color: '#a6adc8' } }
							onMouseEnter={ ( e ) =>
								( e.currentTarget.style.backgroundColor =
									'#2a1f42' )
							}
							onMouseLeave={ ( e ) =>
								( e.currentTarget.style.backgroundColor =
									'transparent' )
							}
							aria-label={ __( 'Copy JSON config', 'surerank' ) }
						>
							{ copied ? (
								<Check
									size={ 16 }
									style={ { color: '#a6e3a1' } }
								/>
							) : (
								<Copy size={ 16 } />
							) }
						</button>
					</Tooltip>
				</div>
				<pre
					className="p-4 pr-24 overflow-x-auto text-sm leading-relaxed font-mono m-0"
					style={ { color: '#cdd6f4' } }
				>
					{ mcpConfig }
				</pre>
			</div>
		</div>
	);
};

export default McpConnectionConfig;
