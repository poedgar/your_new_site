import { __ } from '@wordpress/i18n';
import { Drawer, Container, Text } from '@bsf/force-ui';
import { SureRankLogo } from '@GlobalComponents/icons';
import { AnimatePresence, motion } from 'framer-motion';
import { AIAuthScreen } from '@GlobalComponents/fix-it-for-me';
import UpgradeCpt from '@GlobalComponents/fix-it-for-me/upgrade-cpt';

const AiAuth = ( {
	onClickLearnMore,
	onClickGetStarted,
	open,
	setOpen,
	showUpgrade = false,
} ) => {
	const handleClickLearnMore = () => {
		if ( typeof onClickLearnMore !== 'function' ) {
			return;
		}
		onClickLearnMore();
	};

	const handleGetStarted = () => {
		if ( typeof onClickGetStarted !== 'function' ) {
			return;
		}
		onClickGetStarted();
	};

	return (
		<Drawer
			exitOnEsc
			position="right"
			scrollLock
			setOpen={ setOpen }
			open={ open }
			className="z-999999"
		>
			<Drawer.Panel>
				<Drawer.Header className="px-5 pt-5 pb-0">
					<Container align="center" justify="between">
						<SureRankLogo
							className="w-5 h-5"
							aria-label="SureRank Logo"
						/>
						<Drawer.CloseButton type="button" />
					</Container>
				</Drawer.Header>
				<Drawer.Body className="overflow-x-hidden space-y-3 px-3">
					<AnimatePresence>
						<motion.div
							className="space-y-2 p-2"
							initial={ { opacity: 0 } }
							animate={ { opacity: 1 } }
							exit={ { opacity: 0 } }
							transition={ { duration: 0.3 } }
						>
							{ showUpgrade ? (
								<UpgradeCpt />
							) : (
								<>
									<Text as="h4" weight={ 600 } size={ 16 }>
										{ __(
											'Connect to SureRank AI to Bulk Optimize Your SEO',
											'surerank'
										) }
									</Text>
									<AIAuthScreen
										onClickLearnMore={
											handleClickLearnMore
										}
										onClickGetStarted={ handleGetStarted }
										subheading={ __(
											'SureRank AI makes bulk SEO editing effortless. Get smart, optimized titles & descriptions for all your pages in just a few clicks.',
											'surerank'
										) }
									/>
								</>
							) }
						</motion.div>
					</AnimatePresence>
				</Drawer.Body>
			</Drawer.Panel>
			<Drawer.Backdrop />
		</Drawer>
	);
};

export default AiAuth;
